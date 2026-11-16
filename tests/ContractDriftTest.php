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
}
