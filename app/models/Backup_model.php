<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Creates a SQL dump of the AgriTrace database using the framework's database layer
 * (no external mysqldump binary needed) and records the backups.
 */
class Backup_model extends Model
{
    /** Returns the full dump as SQL text. */
    public function dump()
    {
        $out = "-- AgriTrace database backup\n-- Created: " . date('c') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        // SHOW statements are not part of the query builder, so they use the framework's raw query.
        $tables = $this->db->raw('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $create = $this->db->raw("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n\n";

            $rows = $this->db->table($table)->get_all(PDO::FETCH_ASSOC);
            foreach (array_chunk($rows, 200) as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    $values[] = '(' . implode(',', array_map([$this, 'literal'], array_values($row))) . ')';
                }
                if ($values) {
                    $columns = '`' . implode('`,`', array_keys($chunk[0])) . '`';
                    $out .= "INSERT INTO `{$table}` ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n";
                }
            }
            $out .= "\n";
        }
        return $out . "SET FOREIGN_KEY_CHECKS=1;\n";
    }

    /** Stores a backup record. */
    public function record($path, $url, $size, $driver, $status, $error, $userId)
    {
        $this->db->table('database_backups')->insert([
            'file_path' => $path, 'file_url' => $url, 'size_bytes' => (int) $size, 'storage_driver' => $driver,
            'status' => $status, 'error_message' => $error, 'created_by' => $userId,
        ]);
    }

    public function list_backups($limit = 50)
    {
        return $this->db->table('database_backups')
            ->select('backup_id, file_path, file_url, size_bytes, storage_driver, status, error_message, created_at')
            ->order_by('backup_id', 'DESC')
            ->limit(max(1, min((int) $limit, 200)))
            ->get_all(PDO::FETCH_ASSOC);
    }

    private function literal($value)
    {
        if ($value === null) return 'NULL';
        if (is_int($value) || is_float($value)) return (string) $value;
        $s = str_replace(['\\', "\0", "\n", "\r", "'", "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\Z'], (string) $value);
        return "'" . $s . "'";
    }
}
