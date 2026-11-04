<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminDatabase_model extends Model
{
    public function getSnapshot()
    {
        $stats = [
            'activeAccounts' => (int) $this->db->table('users')->where('is_active', 1)->count(),
            'farms' => (int) $this->db->table('farms')->count(),
            'products' => (int) $this->db->table('products')->count(),
            'batches' => (int) $this->db->table('product_batches')->count(),
            'harvests' => (int) $this->db->table('harvest_records')->count(),
            'shipments' => (int) $this->db->table('shipments')->count(),
            'markets' => (int) $this->db->table('markets')->count(),
            'notifications' => (int) $this->db->table('notifications')->count(),
            'listings' => (int) $this->db->table('marketplace_listings')->count(),
            'orders' => (int) $this->db->table('orders')->count(),
            'revenue' => (float) ($this->db->table('orders')
                ->select_sum('total_amount', 'revenue')
                ->get(PDO::FETCH_ASSOC)['revenue'] ?? 0),
            'groupCount' => (int) $this->db->table('community_groups')->count(),
        ];
        $tables = [
            'users' => ['users', 'id, username, first_name, middle_name, last_name, age, gender, address, contact_number, email, role, profile_pic, is_verified, is_active, created_at', 'id', 'ID,Username,First Name,Middle Name,Last Name,Age,Gender,Address,Contact,Email,Role,Profile Photo,Verified,Active,Created At'],
            'farms' => ['farms', 'farm_id, farmer_id, farm_name, farm_address, province, municipality, barangay, latitude, longitude, farm_size, farm_size_unit, created_at', 'farm_id', 'Farm ID,Farmer ID,Farm Name,Address,Province,Municipality,Barangay,Latitude,Longitude,Farm Size,Unit,Created At'],
            'products' => ['products', 'product_id, product_name, category, description, unit_of_measure, created_at', 'product_id', 'Product ID,Product Name,Category,Description,Unit of Measure,Created At'],
            'batches' => ['product_batches', 'batch_id, batch_code, product_id, farm_id, quantity, unit, harvest_date, current_status, created_at, updated_at', 'batch_id', 'Batch ID,Batch Code,Product ID,Farm ID,Quantity,Unit,Harvest Date,Current Status,Created At,Updated At'],
            'harvests' => ['harvest_records', 'harvest_id, batch_id, farmer_id, harvest_date, quantity_harvested, quality_grade, approval_status, notes, created_at', 'harvest_id', 'Harvest ID,Batch ID,Farmer ID,Harvest Date,Quantity Harvested,Quality Grade,Approval,Notes,Created At'],
            'qrcodes' => ['qr_codes', 'qr_id, batch_id, qr_image_path, is_active, created_at', 'qr_id', 'QR ID,Batch ID,QR Image Path,Active,Created At'],
            'traceability' => ['traceability_logs', 'log_id, batch_id, updated_by, status, location_name, latitude, longitude, remarks, created_at', 'log_id', 'Log ID,Batch ID,Updated By,Status,Location,Latitude,Longitude,Remarks,Created At'],
            'shipments' => ['shipments', 'shipment_id, batch_id, transporter_id, vehicle_number, origin, destination, dispatch_time, delivery_time, shipment_status, remarks, created_at', 'shipment_id', 'Shipment ID,Batch ID,Transporter ID,Vehicle Number,Origin,Destination,Dispatch Time,Delivery Time,Status,Remarks,Created At'],
            'markets' => ['markets', 'market_id, market_name, manager_id, address, province, municipality, contact_number, latitude, longitude, created_at', 'market_id', 'Market ID,Market Name,Manager ID,Address,Province,Municipality,Contact Number,Latitude,Longitude,Created At'],
            'notifications' => ['notifications', 'notification_id, user_id, title, message, channel, delivery_status, created_at', 'notification_id', 'Notification ID,User ID,Title,Message,Channel,Delivery Status,Created At'],
            'weather' => ['weather_records', 'weather_id, farm_id, harvest_id, temperature, humidity, weather_condition, rainfall_mm, recorded_at', 'weather_id', 'Weather ID,Farm ID,Harvest ID,Temperature,Humidity,Condition,Rainfall (mm),Recorded At'],
            'logs' => ['audit_logs', 'audit_id, user_id, action, entity_type, entity_id, details, ip_address, created_at', 'audit_id', 'Audit ID,User ID,Action,Entity Type,Entity ID,Details,IP Address,Created At']
        ];
        $result = [
            'stats' => [
                'activeAccounts' => $stats['activeAccounts'], 'farms' => $stats['farms'],
                'products' => $stats['products'], 'batches' => $stats['batches'],
                'harvests' => $stats['harvests'], 'shipments' => $stats['shipments'],
                'markets' => $stats['markets'], 'notifications' => $stats['notifications'],
                'listings' => $stats['listings'], 'orders' => $stats['orders'],
                'revenue' => $stats['revenue'],
                'groups' => $stats['groupCount']
            ],
            'tables' => []
        ];
        foreach ($tables as $name => $definition) {
            $result['tables'][$name] = $this->getTable($definition[0], $definition[1], $definition[2], $definition[3]);
        }
        $result['tables']['listings'] = $this->getTable('marketplace_listings', 'marketplace_listings.listing_id, marketplace_listings.product_name, marketplace_listings.category, marketplace_listings.description, marketplace_listings.price, marketplace_listings.stock, marketplace_listings.unit, marketplace_listings.location, marketplace_listings.image_url, marketplace_listings.is_available, u.username AS seller, marketplace_listings.created_at', 'marketplace_listings.listing_id', 'Listing ID,Product Name,Category,Description,Price,Stock,Unit,Location,Product Image,Available,Seller,Created At', 'users u', 'u.id = marketplace_listings.seller_id');
        $result['tables']['orders'] = $this->getTable('orders', 'orders.order_id, u.username AS buyer, orders.total_amount, orders.status, orders.created_at', 'orders.order_id', 'Order ID,Buyer,Total,Status,Created At', 'users u', 'u.id = orders.buyer_id');
        $traceCodes = $this->db->table('trace_codes')
            ->select('trace_id, trace_code, order_id, product_name, qr_url, created_at')
            ->order_by('trace_id', 'DESC')
            ->limit(100)
            ->get_all(PDO::FETCH_ASSOC);
        $legacyQrCodes = $result['tables']['qrcodes'];
        $qrRows = array_map(static function ($row) {
            return [
                'trace_id' => $row['qr_id'],
                'record_type' => 'Batch QR',
                'trace_code' => null,
                'order_id' => null,
                'batch_id' => $row['batch_id'],
                'product_name' => null,
                'qr_url' => $row['qr_image_path'],
                'created_at' => $row['created_at'],
            ];
        }, $legacyQrCodes['rows']);
        foreach ($traceCodes as $row) {
            $qrRows[] = [
                'trace_id' => $row['trace_id'],
                'record_type' => 'Trace code',
                'trace_code' => $row['trace_code'],
                'order_id' => $row['order_id'],
                'batch_id' => null,
                'product_name' => $row['product_name'],
                'qr_url' => $row['qr_url'],
                'created_at' => $row['created_at'],
            ];
        }
        usort($qrRows, static function ($a, $b) {
            return (int) $b['trace_id'] <=> (int) $a['trace_id'];
        });
        $result['tables']['qrcodes'] = [
            'columns' => [
                ['field' => 'trace_id', 'label' => 'QR ID'],
                ['field' => 'record_type', 'label' => 'QR Type'],
                ['field' => 'trace_code', 'label' => 'Trace Code'],
                ['field' => 'order_id', 'label' => 'Order ID'],
                ['field' => 'batch_id', 'label' => 'Batch ID'],
                ['field' => 'product_name', 'label' => 'Product'],
                ['field' => 'qr_url', 'label' => 'QR Image'],
                ['field' => 'created_at', 'label' => 'Created At'],
            ],
            'rows' => $qrRows,
        ];
        return $result;
    }

    private function getTable($table, $columns, $orderBy, $labels, $joinTable = null, $joinCondition = null)
    {
        $query = $this->db->table($table)->select($columns);
        if ($joinTable !== null) {
            $query->inner_join($joinTable, $joinCondition);
        }
        $rows = $query->order_by($orderBy, 'DESC')->limit(100)->get_all(PDO::FETCH_ASSOC);
        $fields = $rows ? array_keys($rows[0]) : [];
        $labels = explode(',', $labels);
        $columns = [];
        foreach ($fields as $index => $field) {
            $columns[] = ['field' => $field, 'label' => $labels[$index] ?? $field];
        }
        return ['columns' => $columns, 'rows' => $rows];
    }
}
