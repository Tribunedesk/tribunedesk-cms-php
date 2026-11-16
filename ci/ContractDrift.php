<?php
declare(strict_types=1);

namespace TribuneDesk\Ci;

use InvalidArgumentException;
use RuntimeException;

/**
 * Is the library's contract pin behind the newest tag of tribunedesk-cms-api? If so, move it.
 *
 *     php ci/contract-drift.php --body-out BODY.md [--open-pr-pin TAG]
 *
 * Run by .github/workflows/contract-drift.yml (spec "kontrakt najpierw" D2b in
 * Tribunedesk/tribunedesk-cms-kit; a port of the kit's ci/contract_drift.py). It compares
 * tests/fixtures/contract/VERSION with the newest v* tag:
 *
 * - current: prints so, `behind=false`, exits 0;
 * - ahead (the pin names a tag newer than any tag, e.g. a deleted one): exits 1, changes nothing;
 * - behind, and the open pull request (`--open-pr-pin`, the pin on contract/latest) is already at
 *   the newest tag: changes nothing, `behind=true rebuild=false`, so the workflow keeps that branch
 *   and whatever was pushed onto it;
 * - behind otherwise: moves the pin in all four places (VERSION, the signature vectors,
 *   ContractVersion::TESTED, the guide links in README.md), writes the pull request body to
 *   `--body-out`, `behind=true rebuild=true`, exits 0.
 *
 * `pinned=` and `tag=` go to $GITHUB_OUTPUT with every `behind`. The workflow fails the run while
 * the pin is behind, so it stays red every day until the pull request lands.
 *
 * Network: `git ls-remote` and a shallow `git clone` of the public repository, nothing else.
 * Not part of the library: the WordPress plugin copies src/ only.
 */
final class ContractDrift
{
    public const REPO_URL = 'https://github.com/Tribunedesk/tribunedesk-cms-api';

    private const VERSION_FILE = 'tests/fixtures/contract/VERSION';
    private const VECTORS_FILE = 'tests/fixtures/contract/webhook-signature.json';
    private const TAG_VECTORS = 'examples/vectors/webhook-signature.json';
    private const CONTRACT_VERSION = 'src/ContractVersion.php';
    private const TESTED = "/public const TESTED = '[^']*';/";
    private const README_LINK = '#(tribunedesk-cms-api/blob/)v\d+\.\d+\.\d+(?:-draft)?/#';

    /**
     * Order of contract versions: a draft comes before the release of the same numbers.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function versionKey(string $version): array
    {
        if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)(-draft)?$/', trim($version), $m)) {
            throw new InvalidArgumentException("not a contract version: '$version'");
        }
        return [(int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) && $m[4] !== '' ? 0 : 1];
    }

    /** @param list<string> $lsRemoteLines lines of `git ls-remote --tags --refs` */
    public static function latestTag(array $lsRemoteLines): string
    {
        $best = null;
        foreach ($lsRemoteLines as $line) {
            if (!preg_match('#refs/tags/(v\d+\.\d+\.\d+(?:-draft)?)$#', trim($line), $m)) {
                continue;
            }
            if ($best === null || self::versionKey($m[1]) > self::versionKey($best)) {
                $best = $m[1];
            }
        }
        if ($best === null) {
            throw new RuntimeException('no v* tag found in ' . self::REPO_URL);
        }
        return $best;
    }

    /** @return 'current'|'behind'|'ahead' */
    public static function compare(string $pinned, string $latest): string
    {
        $old = self::versionKey($pinned);
        $new = self::versionKey($latest);
        return $old === $new ? 'current' : ($old < $new ? 'behind' : 'ahead');
    }

    /**
     * Every tagged item of every `**Integrators:**` list, newest version first (as
     * `changelog_items` in tdkit/integrators.py). "Nothing to do." and untagged lines are skipped.
     *
     * @return list<array{version: string, tags: list<string>, action: string}>
     */
    public static function changelogItems(string $changelog): array
    {
        $items = [];
        $version = null;
        $inList = false;
        foreach (preg_split('/\r?\n/', $changelog) ?: [] as $line) {
            if (preg_match('/^## (\d+)\.(\d+)\.(\d+)(-draft)?\b/', $line)) {
                $version = explode(' ', substr($line, 3))[0];
                $inList = false;
                continue;
            }
            if (str_starts_with($line, '## ')) {
                $version = null;
                $inList = false;
                continue;
            }
            if (trim($line) === '**Integrators:**') {
                $inList = true;
                continue;
            }
            if (!$inList || $version === null || !str_starts_with($line, '- ')) {
                continue;
            }
            if (!preg_match('/^- ((?:`[^`]+`(?:,\s*|\s+)?)+):\s*(.+)$/', $line, $m)) {
                continue;
            }
            preg_match_all('/`([^`]+)`/', $m[1], $tags);
            $items[] = ['version' => $version, 'tags' => $tags[1], 'action' => trim($m[2])];
        }
        return $items;
    }

    /**
     * Links of README.md into the contract repository whose file, or whose heading anchor, the
     * tag in `$tagRoot` does not have. Anchors are made as GitHub makes them.
     *
     * @return list<string>
     */
    public static function missingAnchors(string $readme, string $tagRoot): array
    {
        preg_match_all('#https://github\.com/Tribunedesk/tribunedesk-cms-api/blob/[^/\s)]+/([^\s)\#]+)(?:\#([^\s)]+))?#', $readme, $links, PREG_SET_ORDER);
        $missing = [];
        foreach ($links as $link) {
            $file = "$tagRoot/{$link[1]}";
            if (!is_file($file)) {
                $missing[] = $link[0];
                continue;
            }
            $anchor = $link[2] ?? '';
            if ($anchor !== '' && !in_array($anchor, self::anchors((string) file_get_contents($file)), true)) {
                $missing[] = $link[0];
            }
        }
        return $missing;
    }

    /** @return list<string> */
    private static function anchors(string $markdown): array
    {
        preg_match_all('/^#{1,6} +(.+?) *#*$/m', $markdown, $headings);
        return array_map(static function (string $heading): string {
            $slug = preg_replace('/[^\p{L}\p{N}\p{M} _-]/u', '', mb_strtolower($heading, 'UTF-8'));
            return str_replace(' ', '-', (string) $slug);
        }, $headings[1]);
    }

    /** @param list<string> $missingAnchors */
    public static function prBody(string $changelog, string $pinned, string $latest, array $missingAnchors): string
    {
        $low = self::versionKey($pinned);
        $high = self::versionKey($latest);
        $lines = [
            "The contract has a newer tag: `$pinned` → `$latest`.",
            '',
            'CHANGELOG: ' . self::REPO_URL . "/blob/$latest/CHANGELOG.md",
            '',
            'This pull request moves `tests/fixtures/contract/` (VERSION and the signature vectors),',
            '`ContractVersion::TESTED` and the guide links in README.md to the new tag; its CI shows',
            'what the new tag breaks. Landing it does not release the library: a `vX.Y.Z` tag is a',
            'separate decision. Commits pushed onto this branch stay until a newer tag replaces it.',
            '',
            '**Integrators** items in between:',
            '',
        ];
        $items = array_filter(
            self::changelogItems($changelog),
            static fn (array $i): bool => $low < self::versionKey($i['version']) && self::versionKey($i['version']) <= $high
        );
        foreach ($items as $item) {
            $tags = implode(', ', array_map(static fn (string $t): string => "`$t`", $item['tags']));
            $lines[] = "- {$item['version']} $tags: {$item['action']}";
        }
        if ($items === []) {
            $lines[] = '- Nothing to do.';
        }
        if ($missingAnchors !== []) {
            $lines[] = '';
            $lines[] = '**Links in README.md that the new tag does not have** (fix them on this branch):';
            $lines[] = '';
            foreach ($missingAnchors as $link) {
                $lines[] = "- $link";
            }
        }
        return implode("\n", $lines) . "\n";
    }
}
