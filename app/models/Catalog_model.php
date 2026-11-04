<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Catalog_model extends Model
{
    public function findSimilar($name, $limit = 10)
    {
        $normalized = $this->normalize($name);
        $compact = str_replace(' ', '', $normalized);
        return $this->db->raw(
            "SELECT p.product_id, p.product_name, p.category, p.unit_of_measure,
                    CASE WHEN p.normalized_name = ? THEN 'exact'
                         WHEN a.normalized_name = ? THEN 'alias'
                         ELSE 'similar' END AS match_type
             FROM products p
             LEFT JOIN product_catalog_aliases a ON a.product_id = p.product_id
             WHERE REPLACE(COALESCE(p.normalized_name, LOWER(TRIM(p.product_name))), ' ', '') LIKE ?
                OR REPLACE(a.normalized_name, ' ', '') LIKE ?
             GROUP BY p.product_id, p.product_name, p.category, p.unit_of_measure, p.normalized_name, a.normalized_name
             ORDER BY CASE WHEN p.normalized_name = ? OR a.normalized_name = ? THEN 0 ELSE 1 END,
                      p.product_name ASC LIMIT " . max(1, min((int) $limit, 20)),
            [$normalized, $normalized, '%' . $compact . '%', '%' . $compact . '%', $normalized, $normalized]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createOrGet($name, $category, $unit)
    {
        $normalized = $this->normalize($name);
        $compact = str_replace(' ', '', $normalized);
        $existing = $this->db->raw(
            "SELECT p.product_id FROM products p
             LEFT JOIN product_catalog_aliases a ON a.product_id = p.product_id
             WHERE REPLACE(COALESCE(p.normalized_name, LOWER(TRIM(p.product_name))), ' ', '') = ?
                OR REPLACE(a.normalized_name, ' ', '') = ? LIMIT 1",
            [$compact, $compact]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return (int) $existing['product_id'];
        }
        return (int) $this->db->table('products')->insert([
            'product_name' => trim($name), 'normalized_name' => $normalized, 'category' => trim($category),
            'unit_of_measure' => trim($unit), 'description' => null
        ]);
    }

    public function addAlias($productId, $alias, $language = null)
    {
        $alias = trim($alias);
        if ($alias === '') {
            return false;
        }
        return (bool) $this->db->table('product_catalog_aliases')->insert([
            'product_id' => $productId, 'alias_name' => $alias,
            'normalized_name' => $this->normalize($alias), 'language_code' => $language
        ]);
    }

    private function normalize($value)
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower((string) $value, 'UTF-8') : strtolower((string) $value);
        return preg_replace('/\s+/', ' ', trim($value));
    }
}
