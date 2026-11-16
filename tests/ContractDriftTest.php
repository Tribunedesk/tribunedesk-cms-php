<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TribuneDesk\Ci\ContractDrift;

require_once __DIR__ . '/../ci/ContractDrift.php';

/**
 * ci/ContractDrift.php, the daily contract drift of .github/workflows/contract-drift.yml
 * (spec "kontrakt najpierw" D2b in Tribunedesk/tribunedesk-cms-kit).
 */
final class ContractDriftTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::remove($dir);
        }
        putenv('GITHUB_OUTPUT');
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/contract-drift-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->dirs[] = $dir;
        return $dir;
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
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    /** @param array<string, string> $files */
    private static function write(string $root, array $files): void
    {
        foreach ($files as $name => $content) {
            if (!is_dir(dirname("$root/$name"))) {
                mkdir(dirname("$root/$name"), 0777, true);
            }
            file_put_contents("$root/$name", $content);
        }
    }

    private const CHANGELOG = <<<'MD'
# Changelog

## 3.5.0-draft – 2026-12-01

- Guide 3: embeds.

**Integrators:**

- `L1`, `read`: verify it
- Nothing to do.

## 3.4.2-draft – 2026-11-12

**Integrators:**

- `L3`: x

## Notes

**Integrators:**

- `L2`: not a version block

MD;

    public function test_version_key_orders_draft_before_release(): void
    {
        $this->assertLessThan(ContractDrift::versionKey('v3.5.0'), ContractDrift::versionKey('3.5.0-draft'));
        $this->assertGreaterThan(ContractDrift::versionKey('3.4.9-draft'), ContractDrift::versionKey('3.4.10-draft'));
        $this->expectException(InvalidArgumentException::class);
        ContractDrift::versionKey('latest');
    }

    public function test_latest_tag_ignores_foreign_names(): void
    {
        $lines = [
            "a1\trefs/tags/v3.4.1-draft",
            "b2\trefs/tags/v3.4.2-draft",
            "c3\trefs/tags/ship-lock",
            "d4\trefs/tags/v3.4.2",
        ];
        $this->assertSame('v3.4.2', ContractDrift::latestTag($lines));
        $this->expectException(RuntimeException::class);
        ContractDrift::latestTag(["c3\trefs/tags/ship-lock"]);
    }

    public function test_compare(): void
    {
        $this->assertSame('current', ContractDrift::compare('v3.4.2-draft', 'v3.4.2-draft'));
        $this->assertSame('behind', ContractDrift::compare('v3.4.1-draft', 'v3.4.2-draft'));
        $this->assertSame('ahead', ContractDrift::compare('v3.5.0-draft', 'v3.4.2-draft'));
    }

    public function test_changelog_items_skip_nothing_to_do(): void
    {
        $items = ContractDrift::changelogItems(self::CHANGELOG);
        $this->assertSame([
            ['version' => '3.5.0-draft', 'tags' => ['L1', 'read'], 'action' => 'verify it'],
            ['version' => '3.4.2-draft', 'tags' => ['L3'], 'action' => 'x'],
        ], $items);
    }

    public function test_pr_body_lists_integrators_between(): void
    {
        $body = ContractDrift::prBody(self::CHANGELOG, 'v3.4.2-draft', 'v3.5.0-draft', []);
        $this->assertStringContainsString('`v3.4.2-draft` → `v3.5.0-draft`', $body);
        $this->assertStringContainsString(ContractDrift::REPO_URL . '/blob/v3.5.0-draft/CHANGELOG.md', $body);
        $this->assertStringContainsString('- 3.5.0-draft `L1`, `read`: verify it', $body);
        $this->assertStringNotContainsString('`L3`', $body);
        $this->assertStringNotContainsString('Links in README.md', $body);

        $none = ContractDrift::prBody(self::CHANGELOG, 'v3.5.0-draft', 'v3.5.0', ['https://x/gone']);
        $this->assertStringContainsString('- Nothing to do.', $none);
        $this->assertStringContainsString('Links in README.md that the new tag does not have', $none);
        $this->assertStringContainsString('- https://x/gone', $none);
    }

    public function test_missing_anchors_lists_gone_headings(): void
    {
        $tag = $this->tempDir();
        self::write($tag, [
            'guide/3-full-content.md' => "# Level 3\n\n## The “read more” box\n\ntext\n",
            'README.md'               => "# API\n\n## Errors\n",
        ]);
        $base = 'https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.2-draft';
        $readme = "| a | [x]($base/guide/3-full-content.md#the-read-more-box) |\n"
            . "| b | [y]($base/README.md#errors) |\n"
            . "| c | [z]($base/guide/3-full-content.md#gone) |\n"
            . "| d | [w]($base/guide/9-none.md) |\n";
        $this->assertSame(
            ["$base/guide/3-full-content.md#gone", "$base/guide/9-none.md"],
            ContractDrift::missingAnchors($readme, $tag)
        );
    }

    // --- moving the pin, git and the CLI run -------------------------------------------------

    /** A copy of the four pinned places of this repository, as they are on main. */
    private function repoCopy(): string
    {
        $repo = $this->tempDir();
        foreach (['tests/fixtures/contract/VERSION', 'tests/fixtures/contract/webhook-signature.json', 'src/ContractVersion.php', 'README.md'] as $name) {
            self::write($repo, [$name => (string) file_get_contents(__DIR__ . "/../$name")]);
        }
        self::write($repo, ['tests/fixtures/contract/VERSION' => "v3.4.1-draft\n"]);
        $tested = preg_replace("/TESTED = '[^']*'/", "TESTED = '3.4.1-draft'", (string) file_get_contents("$repo/src/ContractVersion.php"));
        self::write($repo, ['src/ContractVersion.php' => (string) $tested]);
        $readme = preg_replace('#cms-api/blob/v[^/]+/#', 'cms-api/blob/v3.4.1-draft/', (string) file_get_contents("$repo/README.md"));
        self::write($repo, ['README.md' => (string) $readme]);
        return $repo;
    }

    /** The tree of a contract tag, as far as the drift reads it. */
    private static function tagTree(string $root): void
    {
        self::write($root, [
            'examples/vectors/webhook-signature.json' => "{\"vectors\": [\"new\"]}\n",
            'CHANGELOG.md' => self::CHANGELOG,
            'README.md' => "# API\n\n## Errors\n",
            'guide/1-article-delivery.md' => "## Article delivery webhook\n## Verifying the signature\n## Fields of your answer\n## Where images come from\n",
            'guide/2-link-back.md' => "# Link back\n",
            'guide/3-full-content.md' => "## Internal links\n## The “read more” box\n## SEO, author and language\n",
            'guide/4-media-library.md' => "## The sync cycle\n## Cursors and expiry\n",
        ]);
    }

    /**
     * A git that answers ls-remote with `$tags` and builds a tag tree on clone.
     *
     * @param list<string> $tags
     * @param list<list<string>> $calls
     */
    private static function fakeGit(array $tags, array &$calls): callable
    {
        return static function (array $args) use ($tags, &$calls): string {
            $calls[] = $args;
            if (in_array('ls-remote', $args, true)) {
                return implode("\n", array_map(static fn (string $t): string => "0000\trefs/tags/$t", $tags)) . "\n";
            }
            if (in_array('clone', $args, true)) {
                $dest = $args[count($args) - 1];
                mkdir($dest, 0777, true);
                self::tagTree($dest);
                return '';
            }
            throw new RuntimeException('unexpected git ' . implode(' ', $args));
        };
    }

    /** @return array<string, string> */
    private static function snapshot(string $repo): array
    {
        $files = [];
        foreach (['tests/fixtures/contract/VERSION', 'tests/fixtures/contract/webhook-signature.json', 'src/ContractVersion.php', 'README.md'] as $name) {
            $files[$name] = (string) file_get_contents("$repo/$name");
        }
        return $files;
    }

    /** @return array<string, string> */
    private static function outputs(string $file): array
    {
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$key, $value] = explode('=', $line, 2);
            $out[$key] = $value;
        }
        return $out;
    }

    public function test_clone_args_disable_autocrlf(): void
    {
        $this->assertSame(
            ['-c', 'core.autocrlf=false', 'clone', '--quiet', '--depth', '1', '--branch', 'v3.4.2-draft', ContractDrift::REPO_URL, '/tmp/x'],
            ContractDrift::cloneArgs('v3.4.2-draft', '/tmp/x')
        );
    }

    public function test_apply_pin_moves_all_four(): void
    {
        $repo = $this->repoCopy();
        $tag = $this->tempDir();
        self::tagTree($tag);
        $before = (string) file_get_contents("$repo/README.md");

        ContractDrift::applyPin($repo, $tag, 'v3.4.2-draft');

        $this->assertSame("v3.4.2-draft\n", file_get_contents("$repo/tests/fixtures/contract/VERSION"));
        $this->assertSame(md5_file("$tag/examples/vectors/webhook-signature.json"), md5_file("$repo/tests/fixtures/contract/webhook-signature.json"));
        $this->assertStringContainsString("public const TESTED = '3.4.2-draft';", (string) file_get_contents("$repo/src/ContractVersion.php"));
        $readme = (string) file_get_contents("$repo/README.md");
        $this->assertSame(0, substr_count($readme, 'blob/v3.4.1-draft/'));
        $this->assertSame(11, substr_count($readme, 'blob/v3.4.2-draft/'));
        $this->assertSame(str_replace('blob/v3.4.1-draft/', 'blob/v3.4.2-draft/', $before), $readme);
    }

    public function test_apply_pin_fails_before_writing_without_tested_constant(): void
    {
        $repo = $this->repoCopy();
        $php = str_replace("TESTED = '3.4.1-draft'", 'TESTED = "3.4.1-draft"', (string) file_get_contents("$repo/src/ContractVersion.php"));
        self::write($repo, ['src/ContractVersion.php' => $php]);
        $tag = $this->tempDir();
        self::tagTree($tag);
        $before = self::snapshot($repo);

        try {
            ContractDrift::applyPin($repo, $tag, 'v3.4.2-draft');
            $this->fail('applyPin moved the pin without ContractVersion::TESTED');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TESTED', $e->getMessage());
        }
        $this->assertSame($before, self::snapshot($repo));
    }

    public function test_run_current_changes_nothing(): void
    {
        $repo = $this->repoCopy();
        $out = $this->tempDir() . '/output';
        putenv("GITHUB_OUTPUT=$out");
        $calls = [];
        $before = self::snapshot($repo);

        $this->expectOutputString("The pin v3.4.1-draft is the newest tag.\n");
        $code = ContractDrift::run(['x', '--body-out', "$repo/body.md"], $repo, self::fakeGit(['v3.4.1-draft'], $calls));

        $this->assertSame(0, $code);
        $this->assertSame(['behind' => 'false'], self::outputs($out));
        $this->assertCount(1, $calls);
        $this->assertSame($before, self::snapshot($repo));
    }

    public function test_run_ahead_fails(): void
    {
        $repo = $this->repoCopy();
        self::write($repo, ['tests/fixtures/contract/VERSION' => "v3.5.0-draft\n"]);
        $calls = [];
        $before = self::snapshot($repo);

        $code = ContractDrift::run(['x', '--body-out', "$repo/body.md"], $repo, self::fakeGit(['v3.4.2-draft'], $calls));

        $this->assertSame(1, $code);
        $this->assertSame($before, self::snapshot($repo));
    }

    public function test_run_open_pr_at_latest_is_left_alone(): void
    {
        $repo = $this->repoCopy();
        $out = $this->tempDir() . '/output';
        putenv("GITHUB_OUTPUT=$out");
        $calls = [];
        $before = self::snapshot($repo);

        $this->expectOutputRegex('/contract\/latest already moves it/');
        $code = ContractDrift::run(
            ['x', '--body-out', "$repo/body.md", '--open-pr-pin', 'v3.4.2-draft'],
            $repo,
            self::fakeGit(['v3.4.1-draft', 'v3.4.2-draft'], $calls)
        );

        $this->assertSame(0, $code);
        $this->assertSame(
            ['behind' => 'true', 'rebuild' => 'false', 'pinned' => 'v3.4.1-draft', 'tag' => 'v3.4.2-draft'],
            self::outputs($out)
        );
        $this->assertCount(1, $calls);
        $this->assertSame($before, self::snapshot($repo));
        $this->assertFileDoesNotExist("$repo/body.md");
    }

    public function test_run_behind_rebuilds(): void
    {
        $repo = $this->repoCopy();
        $out = $this->tempDir() . '/output';
        putenv("GITHUB_OUTPUT=$out");
        $calls = [];

        $this->expectOutputRegex('/README links moved/');
        $code = ContractDrift::run(
            ['x', '--body-out', "$repo/body.md", '--open-pr-pin', ''],
            $repo,
            self::fakeGit(['v3.4.1-draft', 'v3.4.2-draft'], $calls)
        );

        $this->assertSame(0, $code);
        $this->assertSame(
            ['behind' => 'true', 'rebuild' => 'true', 'pinned' => 'v3.4.1-draft', 'tag' => 'v3.4.2-draft'],
            self::outputs($out)
        );
        $this->assertSame("v3.4.2-draft\n", file_get_contents("$repo/tests/fixtures/contract/VERSION"));
        $body = (string) file_get_contents("$repo/body.md");
        $this->assertStringContainsString('`v3.4.1-draft` → `v3.4.2-draft`', $body);
        $this->assertStringContainsString('- 3.4.2-draft `L3`: x', $body);
        $this->assertStringNotContainsString('Links in README.md', $body);
        $this->assertCount(2, $calls);
        $this->assertDirectoryDoesNotExist($calls[1][count($calls[1]) - 1]);
    }

    public function test_run_fails_without_changelog_in_tag(): void
    {
        $repo = $this->repoCopy();
        $before = self::snapshot($repo);
        $calls = [];
        $git = self::fakeGit(['v3.4.2-draft'], $calls);
        $noChangelog = static function (array $args) use ($git): string {
            $out = $git($args);
            if (in_array('clone', $args, true)) {
                unlink($args[count($args) - 1] . '/CHANGELOG.md');
            }
            return $out;
        };

        // On the runner a missing file is only a warning; PHPUnit would turn it into an exception.
        $level = error_reporting(E_ALL & ~E_WARNING);
        try {
            ContractDrift::run(['x', '--body-out', "$repo/body.md"], $repo, $noChangelog);
            $this->fail('a tag without CHANGELOG.md became a pull request body');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CHANGELOG.md', $e->getMessage());
        } finally {
            error_reporting($level);
        }
        $this->assertSame($before, self::snapshot($repo));
        $this->assertFileDoesNotExist("$repo/body.md");
    }

    public function test_workflow_passes_the_pin_only_with_an_open_pull_request(): void
    {
        $workflow = (string) file_get_contents(__DIR__ . '/../.github/workflows/contract-drift.yml');
        $this->assertSame(1, preg_match('/id: open\n(.+?)\n\n/s', $workflow, $m));
        $open = strpos($m[1], 'gh pr list --head contract/latest --state open');
        $this->assertNotFalse($open, 'a branch left by a closed or never-opened pull request must be rebuilt');
        $this->assertLessThan(strpos($m[1], 'git fetch'), $open);
    }

    public function test_workflow_commits_no_workflow_file(): void
    {
        $workflow = (string) file_get_contents(__DIR__ . '/../.github/workflows/contract-drift.yml');
        $this->assertSame(1, preg_match('/^\s*git add (.+)$/m', $workflow, $m));
        $this->assertStringNotContainsString('.github', $m[1]);
        foreach (['tests/fixtures/contract', 'src/ContractVersion.php', 'README.md'] as $place) {
            $this->assertStringContainsString($place, $m[1], "applyPin changes $place, so the workflow must commit it");
        }
    }
}
