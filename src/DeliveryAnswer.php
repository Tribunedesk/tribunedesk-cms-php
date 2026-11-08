<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Builds the answer to an article delivery (ArticleDeliveryResult).
 *
 * Level: 1 (permalink: 2, featured_image: 4) · Routes: webhook article.delivered · Guide: guide/1-article-delivery.md#fields-of-your-answer
 *
 * `cms_post_id` is the contract field (text): a number or a UUID, whatever the CMS uses. The old
 * numeric `post_id` is no longer sent; Tribune Desk reads it only when `cms_post_id` is missing.
 * `permalink` only for a public post: a draft has no public address, and the edit URL is never a
 * permalink. `featured_image` only when the main image came from Tribune Desk (asset_id known),
 * so Tribune Desk can reuse the file next time; it carries the CMS's media id as `cms_media_id`
 * (text, contract 3.4.0) and, when that id is a positive number, also as `attachment_id` (for
 * older Tribune Desk). `social_queue_exclude` goes with `permalink` when the "do not publish on
 * Facebook" field is configured, so a flagged post published at once never enters the Facebook
 * queue (3.4.0).
 */
final class DeliveryAnswer
{
    /**
     * @param array{asset_id: string, url: string, cms_media_id?: string, attachment_id?: int}|null $featured_image
     *        The media id as `cms_media_id` (text) or `attachment_id` (number); one of them is required.
     * @return array<string, mixed>
     */
    public static function build(int|string $cms_post_id, string $status, ?string $edit_url, ?string $permalink, ?array $featured_image, ?bool $social_queue_exclude = null): array
    {
        $answer = [
            'cms_post_id' => (string) $cms_post_id,
            'edit_url'    => $edit_url,
            'status'      => $status,
        ];
        if ($permalink !== null && $permalink !== '') {
            $answer['permalink'] = $permalink;
            if ($social_queue_exclude !== null) {
                $answer['social_queue_exclude'] = $social_queue_exclude;
            }
        }
        if ($featured_image !== null) {
            $attachment_id = $featured_image['attachment_id'] ?? null;
            $media_id      = (string) ($featured_image['cms_media_id'] ?? '');
            if ($media_id === '') {
                $media_id = (string) ($attachment_id ?? '');
            }
            if ($media_id === '') {
                throw new \InvalidArgumentException('featured_image needs cms_media_id or attachment_id');
            }
            unset($featured_image['cms_media_id']);
            if (!is_int($attachment_id) || $attachment_id <= 0) {
                unset($featured_image['attachment_id']);
            }
            $answer['featured_image'] = ['cms_media_id' => $media_id] + $featured_image;
        }

        return $answer;
    }

    /** The answer to a delivery that failed on our side: no internals, the text stays in the log. */
    public static function internal_error(): array
    {
        return ['error' => 'internal_error'];
    }
}
