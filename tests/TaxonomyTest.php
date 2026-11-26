<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use TribuneDesk\Protocol\DeliveryAnswer;
use TribuneDesk\Protocol\DraftRules;
use TribuneDesk\Protocol\Taxonomy;

/** Contract 3.8: the categories and tags of a delivery, how they are found, and the answer. */
final class TaxonomyTest extends TestCase
{
    private const FIELD = [
        'categories' => [['cms_term_id' => '7', 'name' => 'Sport']],
        'tags'       => [['cms_term_id' => '12', 'name' => 'Młynkova'], ['name' => 'Kasia']],
    ];

    private const PLAN = [
        'categories' => [['cms_term_id' => '7', 'name' => 'Sport']],
        'tags'       => [['cms_term_id' => '12', 'name' => 'Młynkova'], ['cms_term_id' => null, 'name' => 'Kasia']],
    ];

    public function test_a_post_not_public_yet_gets_the_plan(): void
    {
        foreach ([null, 'draft', 'pending', 'future'] as $status) {
            $this->assertSame(self::PLAN, Taxonomy::requested($status, self::FIELD), (string) $status);
            $this->assertContains($status ?? 'draft', DraftRules::UPDATABLE);
        }
    }

    public function test_a_published_or_private_post_is_left_alone(): void
    {
        foreach (['publish', 'private', 'trash'] as $status) {
            $this->assertNull(Taxonomy::requested($status, self::FIELD), $status);
        }
    }

    public function test_no_field_or_an_unreadable_one_changes_nothing(): void
    {
        foreach ([null, 'x', 3, [], ['categories' => null], ['tags' => 'Sport'], [['name' => 'Sport']]] as $field) {
            $this->assertNull(Taxonomy::requested(null, $field), json_encode($field) ?: '');
        }
    }

    public function test_a_missing_key_leaves_that_taxonomy_alone_an_empty_list_clears_it(): void
    {
        $this->assertSame(
            ['categories' => null, 'tags' => [['cms_term_id' => null, 'name' => 'Kasia']]],
            Taxonomy::requested('draft', ['tags' => [['name' => 'Kasia']]]),
        );
        $this->assertSame(
            ['categories' => [], 'tags' => null],
            Taxonomy::requested('draft', ['categories' => []]),
        );
    }

    public function test_terms_without_a_name_are_dropped_and_ids_become_text(): void
    {
        $this->assertSame(
            ['categories' => [['cms_term_id' => '7', 'name' => 'Sport']], 'tags' => null],
            Taxonomy::requested(null, ['categories' => [
                ['cms_term_id' => 7, 'name' => 'Sport'],
                ['cms_term_id' => '8'],
                ['cms_term_id' => '9', 'name' => ''],
                'Sport',
            ]]),
        );
    }

    /**
     * The adapter's lookups: ids 7 and 12 are terms of this taxonomy, id 30 is a term of the
     * other one; the names are found ignoring letter case.
     *
     * @return array{callable(string): ?int, callable(string): ?int}
     */
    private static function lookups(): array
    {
        $by_id = static fn (string $id): ?int => match ($id) {
            '7' => 7,
            '12' => 12,
            '30' => Taxonomy::OTHER_TAXONOMY,
            default => null,
        };
        $by_name = static fn (string $name): ?int => match (mb_strtolower($name, 'UTF-8')) {
            'sport' => 7,
            'młynkova' => 12,
            'pogoda' => Taxonomy::OTHER_TAXONOMY,
            default => null,
        };

        return [$by_id, $by_name];
    }

    public function test_resolve_by_id(): void
    {
        [$by_id, $by_name] = self::lookups();
        $this->assertSame(
            ['ids' => [7], 'ignored' => []],
            Taxonomy::resolve([['cms_term_id' => '7', 'name' => 'Old name']], $by_id, $by_name),
        );
    }

    /** Review Focus 4: renamed in the CMS → set by id; id deleted, name exists → set by name. */
    public function test_an_unknown_id_falls_back_to_the_name(): void
    {
        [$by_id, $by_name] = self::lookups();
        $this->assertSame(
            ['ids' => [12], 'ignored' => []],
            Taxonomy::resolve([['cms_term_id' => '99', 'name' => 'MŁYNKOVA']], $by_id, $by_name),
        );
        $this->assertSame(
            ['ids' => [7], 'ignored' => []],
            Taxonomy::resolve([['cms_term_id' => null, 'name' => 'sport']], $by_id, $by_name),
        );
    }

    public function test_neither_id_nor_name_is_an_unknown_term(): void
    {
        [$by_id, $by_name] = self::lookups();
        $this->assertSame(
            ['ids' => [], 'ignored' => [['name' => 'Kasia', 'reason' => Taxonomy::UNKNOWN_TERM]]],
            Taxonomy::resolve([['cms_term_id' => '99', 'name' => 'Kasia']], $by_id, $by_name),
        );
        $this->assertSame('unknown_term', Taxonomy::UNKNOWN_TERM);
    }

    public function test_a_term_of_the_other_taxonomy_is_wrong_taxonomy(): void
    {
        [$by_id, $by_name] = self::lookups();
        $this->assertSame(
            ['ids' => [], 'ignored' => [
                ['name' => 'Wybory', 'reason' => Taxonomy::WRONG_TAXONOMY],
                ['name' => 'Pogoda', 'reason' => Taxonomy::WRONG_TAXONOMY],
            ]],
            Taxonomy::resolve([
                ['cms_term_id' => '30', 'name' => 'Wybory'],
                ['cms_term_id' => null, 'name' => 'Pogoda'],
            ], $by_id, $by_name),
        );
        $this->assertSame('wrong_taxonomy', Taxonomy::WRONG_TAXONOMY);
    }

    public function test_an_id_of_the_other_taxonomy_still_falls_back_to_the_name(): void
    {
        [$by_id, $by_name] = self::lookups();
        $this->assertSame(
            ['ids' => [7], 'ignored' => []],
            Taxonomy::resolve([['cms_term_id' => '30', 'name' => 'Sport']], $by_id, $by_name),
        );
    }

    public function test_duplicates_are_merged_in_first_seen_order(): void
    {
        [$by_id, $by_name] = self::lookups();
        $this->assertSame(
            ['ids' => [12, 7], 'ignored' => []],
            Taxonomy::resolve([
                ['cms_term_id' => '12', 'name' => 'Młynkova'],
                ['cms_term_id' => '7', 'name' => 'Sport'],
                ['cms_term_id' => null, 'name' => 'Sport'],
                ['cms_term_id' => '99', 'name' => 'młynkova'],
            ], $by_id, $by_name),
        );
    }

    public function test_answer_has_the_shape_of_taxonomy_result(): void
    {
        $this->assertSame(
            [
                'categories' => [['cms_term_id' => '7', 'name' => 'Sport']],
                'tags'       => [['cms_term_id' => '12', 'name' => 'Młynkova']],
                'ignored'    => [['name' => 'Kasia', 'reason' => 'unknown_term']],
            ],
            Taxonomy::answer(
                [['cms_term_id' => 7, 'name' => 'Sport']],
                [['cms_term_id' => '12', 'name' => 'Młynkova']],
                [['name' => 'Kasia', 'reason' => 'unknown_term']],
            ),
        );
        $this->assertSame(['categories' => [], 'tags' => [], 'ignored' => []], Taxonomy::answer([], [], []));
    }

    public function test_answer_refuses_an_empty_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Taxonomy::answer([], [], [['name' => 'Kasia', 'reason' => '']]);
    }

    public function test_delivery_answer_carries_taxonomy(): void
    {
        $taxonomy = Taxonomy::answer([['cms_term_id' => '7', 'name' => 'Sport']], [], []);
        $answer = DeliveryAnswer::build(5, DraftRules::UPDATE, null, null, null, null, null, $taxonomy);
        $this->assertSame($taxonomy, $answer['taxonomy']);
        $this->assertArrayNotHasKey('taxonomy', DeliveryAnswer::build(5, DraftRules::UPDATE, null, null, null));
    }
}
