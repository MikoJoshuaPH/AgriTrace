<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminDatabase_model extends Model
{
    public function getSnapshot()
    {
        $revenue = $this->db->table('orders')->select_sum('total_amount', 'revenue')->get(PDO::FETCH_ASSOC);
        $tables = [
            'users' => ['users', 'id, username, first_name, middle_name, last_name, age, gender, address, contact_number, email, role, is_verified, is_active, created_at', 'id', 'ID,Username,First Name,Middle Name,Last Name,Age,Gender,Address,Contact,Email,Role,Verified,Active,Created At'],
            'farms' => ['farms', 'farm_id, farmer_id, farm_name, farm_address, province, municipality, barangay, latitude, longitude, farm_size, farm_size_unit, created_at', 'farm_id', 'Farm ID,Farmer ID,Farm Name,Address,Province,Municipality,Barangay,Latitude,Longitude,Farm Size,Unit,Created At'],
            'products' => ['products', 'product_id, product_name, category, description, unit_of_measure, created_at', 'product_id', 'Product ID,Product Name,Category,Description,Unit of Measure,Created At'],
            'batches' => ['product_batches', 'batch_id, batch_code, product_id, farm_id, quantity, unit, harvest_date, current_status, created_at, updated_at', 'batch_id', 'Batch ID,Batch Code,Product ID,Farm ID,Quantity,Unit,Harvest Date,Current Status,Created At,Updated At'],
            'harvests' => ['harvest_records', 'harvest_id, batch_id, farmer_id, harvest_date, quantity_harvested, quality_grade, notes, created_at', 'harvest_id', 'Harvest ID,Batch ID,Farmer ID,Harvest Date,Quantity Harvested,Quality Grade,Notes,Created At'],
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
                'activeAccounts' => $this->countRows('users', ['is_active' => 1]), 'farms' => $this->countRows('farms'),
                'products' => $this->countRows('products'), 'batches' => $this->countRows('product_batches'),
                'harvests' => $this->countRows('harvest_records'), 'shipments' => $this->countRows('shipments'),
                'markets' => $this->countRows('markets'), 'notifications' => $this->countRows('notifications'),
                'listings' => $this->countRows('marketplace_listings'), 'orders' => $this->countRows('orders'),
                'revenue' => (float) ($revenue['revenue'] ?? 0), 'pendingAccounts' => $this->countRows('users', ['is_active' => 0]),
                'groups' => $this->countRows('community_groups')
            ],
            'tables' => []
        ];
        foreach ($tables as $name => $definition) {
            $result['tables'][$name] = $this->getTable($definition[0], $definition[1], $definition[2], $definition[3]);
        }
        $result['tables']['listings'] = $this->getTable('marketplace_listings', 'marketplace_listings.listing_id, marketplace_listings.product_name, marketplace_listings.category, marketplace_listings.description, marketplace_listings.price, marketplace_listings.stock, marketplace_listings.unit, marketplace_listings.location, marketplace_listings.is_available, u.username AS seller, marketplace_listings.created_at', 'marketplace_listings.listing_id', 'Listing ID,Product Name,Category,Description,Price,Stock,Unit,Location,Available,Seller,Created At', 'users u', 'u.id = marketplace_listings.seller_id');
        $result['tables']['orders'] = $this->getTable('orders', 'orders.order_id, u.username AS buyer, orders.total_amount, orders.status, orders.created_at', 'orders.order_id', 'Order ID,Buyer,Total,Status,Created At', 'users u', 'u.id = orders.buyer_id');
        return $result;
    }

    private function countRows($table, $conditions = [])
    {
        $query = $this->db->table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }
        return (int) $query->count();
    }

    private function getTable($table, $columns, $orderBy, $labels, $joinTable = null, $joinCondition = null)
    {
        $query = $this->db->table($table)->select($columns);
        if ($joinTable !== null) {
            $query->inner_join($joinTable, $joinCondition);
        }
        $rows = $query->order_by($orderBy, 'DESC')->get_all(PDO::FETCH_ASSOC);
        $fields = $rows ? array_keys($rows[0]) : [];
        $labels = explode(',', $labels);
        $columns = [];
        foreach ($fields as $index => $field) {
            $columns[] = ['field' => $field, 'label' => $labels[$index] ?? $field];
        }
        return ['columns' => $columns, 'rows' => $rows];
    }
}
