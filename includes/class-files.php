<?php
/**
 * Attachment files follow their database rows.
 *
 * The database merge moves wp_posts rows for attachments between branches, but
 * the files live in each environment's uploads directory. After a merge this
 * copies the files for every attachment that arrived from the source branch.
 *
 * Where each branch keeps its uploads is configured once:
 *
 *   define('WPBR_UPLOADS_DIRS', [
 *       'main'  => '/var/www/prod/wp-content/uploads',
 *       'stage' => '/var/www/stage/wp-content/uploads',
 *   ]);
 *
 * The branch this site runs on defaults to its own uploads directory. Hosts
 * where environments live on different machines or in object storage hook
 * `wpbr_copy_attachment_file` and do the copy their own way.
 */
defined('ABSPATH') || exit;

class WPBR_Files
{
    /** Uploads base directory for a branch, or null when unknown. */
    public static function uploads_dir(string $branch): ?string
    {
        $map = defined('WPBR_UPLOADS_DIRS') && is_array(WPBR_UPLOADS_DIRS) ? WPBR_UPLOADS_DIRS : [];
        $dir = $map[$branch] ?? null;
        if (!$dir && $branch === WPBR_Dolt::current_branch()) {
            $dir = wp_get_upload_dir()['basedir'] ?? null;
        }
        $dir = apply_filters('wpbr_uploads_dir', $dir, $branch);
        return $dir ? rtrim(str_replace('\\', '/', $dir), '/') : null;
    }

    /** How many attachments were added or changed on the source since the merge base. */
    public static function incoming_count(WPBR_Dolt $target, string $base, string $source_hash): int
    {
        $prefix = $GLOBALS['wpdb']->prefix;
        return count($target->diff_rows($base, $source_hash, $prefix . 'posts', 100000, "to_post_type = 'attachment' AND diff_type IN ('added', 'modified')"));
    }

    /**
     * Relative upload paths (original plus every generated size) for attachments
     * that were added or changed on the source branch since the merge base.
     * Read from the target branch after the merge so paths reflect the merged state.
     */
    public static function incoming_paths(WPBR_Dolt $target, string $base, string $source_hash): array
    {
        $prefix = $GLOBALS['wpdb']->prefix;
        $rows = $target->diff_rows($base, $source_hash, $prefix . 'posts', 100000, "to_post_type = 'attachment' AND diff_type IN ('added', 'modified')");
        $paths = [];
        foreach ($rows as $r) {
            $id = (int) $r['to_ID'];
            $file = (string) $target->var('SELECT meta_value FROM ' . $target->ident($prefix . 'postmeta') . " WHERE post_id = ? AND meta_key = '_wp_attached_file' LIMIT 1", [$id]);
            if ($file === '') continue;
            $paths[$id][] = $file;
            $dir = dirname($file);
            $dir = $dir === '.' ? '' : $dir . '/';
            $meta = $target->var('SELECT meta_value FROM ' . $target->ident($prefix . 'postmeta') . " WHERE post_id = ? AND meta_key = '_wp_attachment_metadata' LIMIT 1", [$id]);
            $m = is_string($meta) && is_serialized($meta) ? @unserialize($meta, ['allowed_classes' => false]) : null;
            if (is_array($m)) {
                foreach (($m['sizes'] ?? []) as $s) {
                    if (!empty($s['file'])) $paths[$id][] = $dir . $s['file'];
                }
                if (!empty($m['original_image'])) $paths[$id][] = $dir . $m['original_image'];
            }
            $paths[$id] = array_values(array_unique($paths[$id]));
        }
        return $paths;
    }

    /** Copy the files for the given attachments from one branch's uploads to another's. */
    public static function copy(array $paths_by_id, string $source_branch, string $target_branch): array
    {
        $out = ['attachments' => count($paths_by_id), 'copied' => 0, 'unchanged' => 0, 'missing' => [], 'skipped' => null];
        if (!$paths_by_id) return $out;
        $src_dir = self::uploads_dir($source_branch);
        $dst_dir = self::uploads_dir($target_branch);
        if (!$src_dir || !$dst_dir) {
            $out['skipped'] = sprintf('Uploads directory unknown for %s. Set WPBR_UPLOADS_DIRS to copy attachment files.', !$src_dir ? $source_branch : $target_branch);
            return $out;
        }
        if ($src_dir === $dst_dir) {
            $out['skipped'] = 'Both branches share one uploads directory; nothing to copy.';
            return $out;
        }
        foreach ($paths_by_id as $id => $rels) {
            foreach ($rels as $rel) {
                $rel = ltrim(str_replace('\\', '/', $rel), '/');
                if ($rel === '' || str_contains($rel, '..')) continue;
                $src = $src_dir . '/' . $rel;
                $dst = $dst_dir . '/' . $rel;
                // A host can take over the copy (rsync, S3, ...) and return true/false.
                $handled = apply_filters('wpbr_copy_attachment_file', null, $src, $dst, $rel, $source_branch, $target_branch, (int) $id);
                if ($handled !== null) {
                    if ($handled) $out['copied']++; else $out['missing'][] = $rel;
                    continue;
                }
                if (!is_file($src)) { $out['missing'][] = $rel; continue; }
                if (is_file($dst) && filesize($dst) === filesize($src) && filemtime($dst) >= filemtime($src)) { $out['unchanged']++; continue; }
                if (!is_dir(dirname($dst))) wp_mkdir_p(dirname($dst));
                if (@copy($src, $dst)) { @touch($dst, filemtime($src)); $out['copied']++; } else { $out['missing'][] = $rel; }
            }
        }
        return $out;
    }

    /** One line for a notice or the CLI. */
    public static function describe(array $r): string
    {
        if (!$r['attachments']) return '';
        if ($r['skipped']) return $r['attachments'] . ' attachment(s) arrived; files not copied: ' . $r['skipped'];
        $s = sprintf('%d attachment file(s) copied', $r['copied']);
        if ($r['unchanged']) $s .= sprintf(', %d already present', $r['unchanged']);
        if ($r['missing']) $s .= sprintf(', %d missing on the source (%s)', count($r['missing']), implode(', ', array_slice($r['missing'], 0, 3)));
        return $s . '.';
    }
}
