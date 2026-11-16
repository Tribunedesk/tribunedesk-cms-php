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

    /**
     * Runs git with an argument list (no shell) and returns its stdout.
     *
     * @param list<string> $args
     */
    public static function git(array $args): string
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start git');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException('git ' . implode(' ', $args) . " failed ($code): " . trim($stderr));
        }
        return $stdout;
    }

    /**
     * A shallow clone of one tag. autocrlf off: the vectors are compared byte for byte in CI.
     *
     * @return list<string>
     */
    public static function cloneArgs(string $tag, string $dest): array
    {
        return ['-c', 'core.autocrlf=false', 'clone', '--quiet', '--depth', '1', '--branch', $tag, self::REPO_URL, $dest];
    }

    /**
     * Moves the pin of `$repo` to `$latest` in all four places, from the tag tree in `$tagRoot`.
     * Everything is checked before anything is written.
     */
    public static function applyPin(string $repo, string $tagRoot, string $latest): void
    {
        $versionPhp = (string) file_get_contents("$repo/" . self::CONTRACT_VERSION);
        if (preg_match_all(self::TESTED, $versionPhp) !== 1) {
            throw new RuntimeException(self::CONTRACT_VERSION . " has no single `public const TESTED = '…';`");
        }
        $vectors = "$tagRoot/" . self::TAG_VECTORS;
        if (!is_file($vectors)) {
            throw new RuntimeException("$latest has no " . self::TAG_VECTORS);
        }
        $readme = (string) file_get_contents("$repo/README.md");

        file_put_contents("$repo/" . self::VERSION_FILE, "$latest\n");
        if (!copy($vectors, "$repo/" . self::VECTORS_FILE)) {
            throw new RuntimeException('cannot copy ' . self::TAG_VECTORS);
        }
        $tested = "public const TESTED = '" . substr($latest, 1) . "';";
        file_put_contents("$repo/" . self::CONTRACT_VERSION, (string) preg_replace(self::TESTED, $tested, $versionPhp));
        file_put_contents("$repo/README.md", (string) preg_replace(self::README_LINK, '${1}' . $latest . '/', $readme));
    }

    /**
     * The CLI run (see the class comment). `$git` takes an argument list and returns stdout.
     *
     * @param list<string> $argv
     * @param callable(list<string>): string $git
     */
    public static function run(array $argv, string $repo, callable $git): int
    {
        $bodyOut = null;
        $openPrPin = '';
        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '--body-out' && isset($argv[$i + 1])) {
                $bodyOut = $argv[++$i];
            } elseif ($argv[$i] === '--open-pr-pin' && isset($argv[$i + 1])) {
                $openPrPin = trim($argv[++$i]);
            } else {
                fwrite(STDERR, "unknown argument: {$argv[$i]}\n");
                return 2;
            }
        }
        if ($bodyOut === null) {
            fwrite(STDERR, "usage: php ci/contract-drift.php --body-out FILE [--open-pr-pin TAG]\n");
            return 2;
        }

        $pinned = trim((string) file_get_contents("$repo/" . self::VERSION_FILE));
        self::versionKey($pinned);
        $lines = preg_split('/\r?\n/', $git(['ls-remote', '--tags', '--refs', self::REPO_URL])) ?: [];
        $latest = self::latestTag($lines);
        $state = self::compare($pinned, $latest);
        if ($state === 'current') {
            echo "The pin $pinned is the newest tag.\n";
            self::output(['behind' => 'false']);
            return 0;
        }
        if ($state === 'ahead') {
            fwrite(STDERR, "The pin $pinned is newer than the newest tag $latest.\n");
            return 1;
        }
        if ($openPrPin === $latest) {
            echo "The pin $pinned is behind $latest; contract/latest already moves it.\n";
            self::output(['behind' => 'true', 'rebuild' => 'false', 'pinned' => $pinned, 'tag' => $latest]);
            return 0;
        }

        $tmp = sys_get_temp_dir() . '/contract-drift-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0777, true);
        try {
            $source = "$tmp/cms-api";
            $git(self::cloneArgs($latest, $source));
            $changelog = (string) file_get_contents("$source/CHANGELOG.md");
            self::applyPin($repo, $source, $latest);
            $missing = self::missingAnchors((string) file_get_contents("$repo/README.md"), $source);
        } finally {
            self::remove($tmp);
        }
        file_put_contents($bodyOut, self::prBody($changelog, $pinned, $latest, $missing));
        echo "The pin $pinned is behind $latest; VERSION, vectors, ContractVersion and README links moved.\n";
        self::output(['behind' => 'true', 'rebuild' => 'true', 'pinned' => $pinned, 'tag' => $latest]);
        return 0;
    }

    /** @param array<string, string> $values */
    private static function output(array $values): void
    {
        $target = getenv('GITHUB_OUTPUT');
        if ($target === false || $target === '') {
            return;
        }
        $text = '';
        foreach ($values as $key => $value) {
            $text .= "$key=$value\n";
        }
        file_put_contents($target, $text, FILE_APPEND);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove("$path/$name");
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @chmod($path, 0666);
            unlink($path);
        }
    }
}
