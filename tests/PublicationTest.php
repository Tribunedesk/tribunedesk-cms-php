<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use TribuneDesk\Protocol\DeliveryAnswer;
use TribuneDesk\Protocol\DraftRules;
use TribuneDesk\Protocol\Publication;

/** Contract 3.7: the publication mode of a delivery and the state the answer reports. */
final class PublicationTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const AT = self::NOW + 86_400;
    private const AT_TEXT = '2027-01-16T08:00:00Z';

    /** @return array<string, array{?string, ?array<string, mixed>, bool, string, ?int}> */
    public static function matrix(): array
    {
        $draft = ['mode' => 'draft'];
        $publish = ['mode' => 'publish'];
        $schedule = ['mode' => 'schedule', 'at' => gmdate('Y-m-d\TH:i:s\Z', self::AT)];
        $k = Publication::KEEP;

        return [
            'new, no field, site keeps drafts' => [null, null, false, 'draft', null],
            'new, no field, site publishes'    => [null, null, true, 'publish', null],
            'new, draft'                       => [null, $draft, true, 'draft', null],
            'new, publish'                     => [null, $publish, false, 'publish', null],
            'new, schedule'                    => [null, $schedule, false, 'future', self::AT],
            'draft, no field'                  => ['draft', null, true, $k, null],
            'draft, draft'                     => ['draft', $draft, false, 'draft', null],
            'draft, publish'                   => ['draft', $publish, false, 'publish', null],
            'draft, schedule'                  => ['draft', $schedule, false, 'future', self::AT],
            'pending, schedule'                => ['pending', $schedule, false, 'future', self::AT],
            'scheduled, no field'              => ['future', null, true, $k, null],
            'scheduled, draft'                 => ['future', $draft, false, 'draft', null],
            'scheduled, publish'               => ['future', $publish, false, 'publish', null],
            'scheduled, new time'              => ['future', $schedule, false, 'future', self::AT],
            'published, no field'              => ['publish', null, false, $k, null],
            'published, draft'                 => ['publish', $draft, false, $k, null],
            'published, publish'               => ['publish', $publish, false, $k, null],
            'published, schedule'              => ['publish', $schedule, false, $k, null],
            'private, draft'                   => ['private', $draft, false, $k, null],
            'private, schedule'                => ['private', $schedule, false, $k, null],
        ];
    }

    /**
     * @dataProvider matrix
     * @param array<string, mixed>|null $publication
     */
    public function test_target(?string $existing, ?array $publication, bool $site_publishes, string $status, ?int $date_gmt): void
    {
        $this->assertSame(
            ['status' => $status, 'date_gmt' => $date_gmt],
            Publication::target($existing, $publication, $site_publishes, self::NOW),
        );
    }

    public function test_a_time_already_past_is_published_at_once(): void
    {
        foreach ([self::NOW, self::NOW - 60] as $at) {
            $schedule = ['mode' => 'schedule', 'at' => gmdate('Y-m-d\TH:i:s\Z', $at)];
            $this->assertSame(['status' => 'publish', 'date_gmt' => null], Publication::target('draft', $schedule, false, self::NOW));
        }
    }

    public function test_the_time_takes_any_offset(): void
    {
        $schedule = ['mode' => 'schedule', 'at' => '2027-01-16T09:00:00+01:00'];
        $this->assertSame(['status' => 'future', 'date_gmt' => 1_800_086_400], Publication::target(null, $schedule, false, self::NOW));
    }

    /** A field it cannot read is ignored, like any field it does not know: the post keeps its state. */
    public function test_an_unreadable_field_is_like_no_field(): void
    {
        $bad = [
            ['mode' => 'later'],
            ['mode' => 'schedule'],
            ['mode' => 'schedule', 'at' => 'tomorrow morning'],
            ['mode' => 'schedule', 'at' => '2027-01-16T08:00:00'],
            ['mode' => ['publish']],
        ];
        foreach ($bad as $publication) {
            $this->assertSame(['status' => Publication::KEEP, 'date_gmt' => null], Publication::target('draft', $publication, true, self::NOW));
            $this->assertSame(['status' => 'draft', 'date_gmt' => null], Publication::target(null, $publication, false, self::NOW));
        }
    }

    public function test_answer(): void
    {
        $this->assertSame(['status' => 'draft', 'at' => null], Publication::answer('draft', self::AT));
        $this->assertSame(['status' => 'draft', 'at' => null], Publication::answer('pending', null));
        $this->assertSame(['status' => 'scheduled', 'at' => '2027-01-16T08:00:00Z'], Publication::answer('future', self::AT));
        $this->assertSame(['status' => 'published', 'at' => '2027-01-16T08:00:00Z'], Publication::answer('publish', self::AT));
        $this->assertSame(['status' => 'published', 'at' => null], Publication::answer('publish', null));
        $this->assertNull(Publication::answer('future', null));
        $this->assertNull(Publication::answer('private', self::AT));
        $this->assertNull(Publication::answer('trash', self::AT));
        $this->assertSame(self::AT_TEXT, gmdate('Y-m-d\TH:i:s\Z', self::AT));
    }

    public function test_a_scheduled_post_takes_a_new_send_like_a_draft(): void
    {
        $this->assertSame(DraftRules::UPDATE, DraftRules::action('future'));
        $this->assertSame(DraftRules::SKIP, DraftRules::action('publish'));
        $this->assertSame(DraftRules::SKIP, DraftRules::action('private'));
    }

    public function test_delivery_answer_carries_publication(): void
    {
        $publication = ['status' => 'scheduled', 'at' => self::AT_TEXT];
        $answer = DeliveryAnswer::build(7, 'created', null, null, null, null, $publication);
        $this->assertSame($publication, $answer['publication']);
        $this->assertArrayNotHasKey('publication', DeliveryAnswer::build(7, 'created', null, null, null));
    }
}
