<?php

namespace Coins\Catalog;

/**
 * Finds or creates the `designer` post for a person, by DesignerCredits::key() — so "Таран
 * Володимир" and "Володимир Таран" are one post. Loads every designer once per instance (~100 rows).
 *
 * Only posts whose title is itself one clean name are matchable; the phrase posts the old
 * comma-only parser created ("аверс: X; реверс: Y") are ignored here and deleted by
 * `wp nbu parse-souvenir --designers-only` once no coin points at them.
 */
final class DesignerRegistry
{
    public const POST_TYPE = 'designer';

    /** @var array<string,int> key => post ID */
    private array $byKey = [];

    /** @var array<string,string> key => preferred spelling, used when creating */
    private array $canonical;

    private bool $dryRun;

    private int $created = 0;

    /**
     * @param array<string,string> $canonical key => spelling to give a new post
     */
    public function __construct(array $canonical = [], bool $dryRun = false)
    {
        $this->canonical = $canonical;
        $this->dryRun    = $dryRun;

        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        foreach ($posts as $post) {
            $name = DesignerCredits::cleanName($post->post_title);
            if ($name === '' || preg_match('~[;:,()]~u', $name)) {
                continue; // a phrase, not a person
            }
            $this->byKey[DesignerCredits::key($name)] ??= (int) $post->ID;
        }
    }

    /**
     * @param array<int,string> $names
     * @return array<int,int> post IDs, in the given order (0 for a would-be-created post in a dry run)
     */
    public function idsFor(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $id = $this->idFor($name);
            if ($id > 0 || $this->dryRun) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function idFor(string $name): int
    {
        $key = DesignerCredits::key($name);
        if (isset($this->byKey[$key])) {
            return $this->byKey[$key];
        }
        if ($this->dryRun) {
            $this->created++;
            $this->byKey[$key] = 0;

            return 0;
        }

        $id = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => $this->canonical[$key] ?? $name,
        ], true);
        if (is_wp_error($id)) {
            return 0;
        }
        $this->created++;

        return $this->byKey[$key] = (int) $id;
    }

    public function createdCount(): int
    {
        return $this->created;
    }

    /**
     * Renames matched posts to the canonical spelling (e.g. the order NBU uses most).
     *
     * @return array<int,array{0:string,1:string}> id => [old, new]
     */
    public function applyCanonicalTitles(): array
    {
        $renamed = [];
        foreach ($this->byKey as $key => $id) {
            $want = $this->canonical[$key] ?? null;
            if (!$id || $want === null) {
                continue;
            }
            $have = get_the_title($id);
            if ($have !== $want) {
                $renamed[$id] = [$have, $want];
                if (!$this->dryRun) {
                    wp_update_post(['ID' => $id, 'post_title' => $want]);
                }
            }
        }

        return $renamed;
    }

    /** @return array<int,true> designer IDs any coin's role field points at */
    public static function referencedIds(): array
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(DesignerCredits::ROLES), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a generated run of %s, one per role, passed through prepare().
        $rows = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", ...DesignerCredits::ROLES));

        $ids = [];
        foreach ($rows as $value) {
            foreach ((array) maybe_unserialize($value) as $id) {
                if ((int) $id > 0) {
                    $ids[(int) $id] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * Deletes every designer post no coin references. Designers carry no data of their own beyond
     * the title (checked when this was written: no content, `full_name`, `note` or thumbnail).
     *
     * @return array<int,string> id => title of each deleted (or, in a dry run, deletable) post
     */
    public function deleteUnreferenced(): array
    {
        $referenced = self::referencedIds();
        $deleted    = [];
        $posts      = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        foreach ($posts as $id) {
            if (isset($referenced[(int) $id])) {
                continue;
            }
            $deleted[(int) $id] = get_the_title($id);
            if (!$this->dryRun) {
                wp_delete_post((int) $id, true);
            }
        }

        return $deleted;
    }
}
