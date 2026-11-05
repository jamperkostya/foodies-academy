<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Brings a copy of the live foodies.academy database (SITE_DB_*) to what the site expects:
 *  - the WPML translations that are not Russian are deleted (posts with their meta, comments
 *    and terms; image attachments stay, the site never lists them),
 *  - the lifehacks written for the site (database/content/lifehacks.json) are added,
 *  - old likes and views go into the plugins' counters, video recipes come from YouTube.
 * Safe to re-run: every step skips what is already done.
 */
class PrepareSiteDatabase extends Command
{
    protected $signature = 'site:prepare-db
        {--force : Don\'t ask for confirmation}
        {--no-videos : Skip the YouTube import}';

    protected $description = 'Adapt a copy of the foodies.academy database to the site';

    private ConnectionInterface $db;

    public function handle(): int
    {
        $this->db = DB::connection('site');
        $name = $this->db->getDatabaseName();
        if (! $this->option('force') && ! $this->confirm("Change the database \"$name\"? Make a backup first.")) {
            return self::FAILURE;
        }

        $this->deleteTranslations();
        $this->importLifehacks();
        $this->call('likes:import-legacy');
        $this->call('views:import-legacy');
        if (! $this->option('no-videos')) {
            $this->call('videos:sync');
        }
        $this->call('cache:clear');
        $this->call('slugs:check');

        return self::SUCCESS;
    }

    private function deleteTranslations(): void
    {
        if (! $this->db->getSchemaBuilder()->hasTable('icl_translations')) {
            $this->info('No WPML tables: nothing to delete.');

            return;
        }
        $foreign = fn () => $this->db->table('icl_translations')->where('language_code', '<>', 'ru');

        // Posts of every type but attachments, with their revisions.
        $postIds = $foreign()->where('element_type', 'like', 'post\_%')->where('element_type', '<>', 'post_attachment')->pluck('element_id');
        $postIds = $postIds->merge($this->db->table('posts')->whereIn('post_parent', $postIds)->where('post_type', 'revision')->pluck('ID'))->unique();
        $touchedTerms = $this->db->table('term_relationships')->whereIn('object_id', $postIds)->pluck('term_taxonomy_id')->unique();
        foreach ($postIds->chunk(500) as $ids) {
            $commentIds = $this->db->table('comments')->whereIn('comment_post_ID', $ids)->pluck('comment_ID');
            $this->db->table('commentmeta')->whereIn('comment_id', $commentIds)->delete();
            $this->db->table('comments')->whereIn('comment_ID', $commentIds)->delete();
            $this->db->table('postmeta')->whereIn('post_id', $ids)->delete();
            $this->db->table('term_relationships')->whereIn('object_id', $ids)->delete();
            $this->db->table('posts')->whereIn('ID', $ids)->delete();
        }

        // Terms (element_id is the term_taxonomy_id).
        $ttIds = $foreign()->where('element_type', 'like', 'tax\_%')->pluck('element_id');
        $termIds = $this->db->table('term_taxonomy')->whereIn('term_taxonomy_id', $ttIds)->pluck('term_id');
        $this->db->table('term_relationships')->whereIn('term_taxonomy_id', $ttIds)->delete();
        $this->db->table('term_taxonomy')->whereIn('term_taxonomy_id', $ttIds)->delete();
        // A term can be in several taxonomies: only delete the ones left without any.
        $orphans = $termIds->diff($this->db->table('term_taxonomy')->whereIn('term_id', $termIds)->pluck('term_id'));
        $this->db->table('termmeta')->whereIn('term_id', $orphans)->delete();
        $this->db->table('terms')->whereIn('term_id', $orphans)->delete();

        $foreign()->where('element_type', '<>', 'post_attachment')->delete();
        $this->recount($touchedTerms->diff($ttIds));

        $this->info(sprintf('Translations: deleted %d posts and %d terms that were not in Russian.', $postIds->count(), $ttIds->count()));
    }

    /** term_taxonomy.count as WordPress keeps it: the published posts in the term. */
    private function recount(Collection $ttIds): void
    {
        foreach ($ttIds as $ttId) {
            $count = $this->db->table('term_relationships as r')
                ->join('posts as p', 'p.ID', '=', 'r.object_id')
                ->where('r.term_taxonomy_id', $ttId)
                ->where('p.post_status', 'publish')
                ->count();
            $this->db->table('term_taxonomy')->where('term_taxonomy_id', $ttId)->update(['count' => $count]);
        }
    }

    private function importLifehacks(): void
    {
        $data = json_decode(file_get_contents(database_path('content/lifehacks.json')), true, flags: JSON_THROW_ON_ERROR);

        $categories = [];
        foreach ($data['categories'] as $category) {
            $categories[$category['slug']] = $this->termTaxonomyId('lifehack_category', $category);
        }

        $existing = $this->db->table('posts')->where('post_type', 'lifehack')->pluck('post_name')->flip();
        // The author of the lifehacks, or the first user when the database has no such user.
        $users = $this->db->table('users')->whereIn('ID', array_column($data['lifehacks'], 'author'))->pluck('ID')->flip();
        $fallbackAuthor = (int) $this->db->table('users')->min('ID');

        $added = 0;
        foreach ($data['lifehacks'] as $lifehack) {
            if ($existing->has($lifehack['slug'])) {
                continue;
            }
            $this->db->transaction(function () use ($lifehack, $categories, $users, $fallbackAuthor) {
                $id = $this->db->table('posts')->insertGetId([
                    'post_author' => $users->has($lifehack['author']) ? $lifehack['author'] : $fallbackAuthor,
                    'post_date' => $lifehack['date'],
                    'post_date_gmt' => $lifehack['date_gmt'],
                    'post_content' => $lifehack['content'],
                    'post_title' => $lifehack['title'],
                    'post_excerpt' => $lifehack['excerpt'],
                    'post_status' => 'publish',
                    'comment_status' => 'open',
                    'ping_status' => 'closed',
                    'post_password' => '',
                    'post_name' => $lifehack['slug'],
                    'to_ping' => '',
                    'pinged' => '',
                    'post_modified' => $lifehack['date'],
                    'post_modified_gmt' => $lifehack['date_gmt'],
                    'post_content_filtered' => '',
                    'post_parent' => 0,
                    'guid' => '',
                    'menu_order' => 0,
                    'post_type' => 'lifehack',
                    'post_mime_type' => '',
                    'comment_count' => 0,
                ], 'ID');
                foreach ($lifehack['meta'] as $key => $value) {
                    $this->db->table('postmeta')->insert(['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value]);
                }
                foreach ($lifehack['categories'] as $slug) {
                    $this->db->table('term_relationships')->insert(['object_id' => $id, 'term_taxonomy_id' => $categories[$slug], 'term_order' => 0]);
                }
            });
            $added++;
        }
        $this->recount(collect($categories));

        $this->info(sprintf('Lifehacks: %d added, %d were there already.', $added, count($data['lifehacks']) - $added));
    }

    /** The term's taxonomy row, created when the database has no term with this slug there. */
    private function termTaxonomyId(string $taxonomy, array $term): int
    {
        $ttId = $this->db->table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', $taxonomy)
            ->where('t.slug', $term['slug'])
            ->value('tt.term_taxonomy_id');
        if ($ttId) {
            return (int) $ttId;
        }
        $termId = $this->db->table('terms')->insertGetId(['name' => $term['name'], 'slug' => $term['slug'], 'term_group' => 0], 'term_id');

        return (int) $this->db->table('term_taxonomy')->insertGetId([
            'term_id' => $termId, 'taxonomy' => $taxonomy, 'description' => $term['description'], 'parent' => 0, 'count' => 0,
        ], 'term_taxonomy_id');
    }
}
