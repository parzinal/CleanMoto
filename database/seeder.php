<?php
echo "===========================================\n";echo "User:   user@xeroclaro.com / user123\n";echo "Staff:  staff@xeroclaro.com / staff123\n";echo "Admin:  admin@xeroclaro.com / admin123\n";echo "===========================================\n";echo "Default Login Credentials:\n";echo "\n===========================================\n";$seeder->run();$seeder = new Seeder();// Run seeder}    }        echo "\n";                }            }                echo "  ✗ Error creating service {$service['name']}: {$e->getMessage()}\n";            } catch (PDOException $e) {                echo "  ✓ Created/Updated service: {$service['name']} (₱{$service['price']})\n";                $stmt->execute($service);            try {        foreach ($services as $service) {                ");                status = VALUES(status)                description = VALUES(description),                image = VALUES(image),                duration = VALUES(duration),                price = VALUES(price),                name = VALUES(name),            ON DUPLICATE KEY UPDATE             VALUES (:label, :name, :price, :duration, :image, :description, :status)            INSERT INTO services (label, name, price, duration, image, description, status)         $stmt = $this->db->prepare("                ];            ]                'status' => 'active'                'description' => 'Specialized treatment for stubborn odors using ozone treatment and antibacterial solutions.',                'image' => 'odor-treatment.jpg',                'duration' => 35,                'price' => 200.00,                'name' => 'Odor Elimination Treatment',                'label' => 'odor',            [            ],                'status' => 'active'                'description' => 'Professional visor cleaning, scratch removal treatment, and anti-fog coating application.',                'image' => 'visor-treatment.jpg',                'duration' => 20,                'price' => 120.00,                'name' => 'Visor Treatment',                'label' => 'visor',            [            ],                'status' => 'active'                'description' => 'Focuses on interior padding, cheek pads, and liner. Includes deep sanitization and deodorizing.',                'image' => 'interior-cleaning.jpg',                'duration' => 40,                'price' => 250.00,                'name' => 'Interior Only Cleaning',                'label' => 'interior',            [            ],                'status' => 'active'                'description' => 'Quick external wipe down and visor cleaning. Ideal for on-the-go riders.',                'image' => 'express-cleaning.jpg',                'duration' => 15,                'price' => 100.00,                'name' => 'Express Quick Clean',                'label' => 'express',            [            ],                'status' => 'active'                'description' => 'Our most comprehensive service. Includes premium deep clean plus scratch removal, polish, and hydrophobic coating for visor.',                'image' => 'deluxe-cleaning.jpg',                'duration' => 90,                'price' => 750.00,                'name' => 'Deluxe Full Service',                'label' => 'deluxe',            [            ],                'status' => 'active'                'description' => 'Complete helmet restoration including deep cleaning of all removable parts, anti-bacterial treatment, and conditioning.',                'image' => 'premium-cleaning.jpg',                'duration' => 60,                'price' => 500.00,                'name' => 'Premium Deep Clean',                'label' => 'premium',            [            ],                'status' => 'active'                'description' => 'Includes basic cleaning plus interior padding cleaning, deodorizing, and UV sanitization.',                'image' => 'standard-cleaning.jpg',                'duration' => 45,                'price' => 300.00,                'name' => 'Standard Helmet Cleaning',                'label' => 'standard',            [            ],                'status' => 'active'                'description' => 'External shell cleaning, visor cleaning, and basic sanitization. Perfect for regular maintenance.',                'image' => 'basic-cleaning.jpg',                'duration' => 30,                'price' => 150.00,                'name' => 'Basic Helmet Cleaning',                'label' => 'basic',            [        $services = [                echo "Seeding helmet cleaning services...\n";    private function seedServices() {     */     * Seed services table with helmet cleaning services    /**        }        echo "\n";                }            }                echo "  ✗ Error creating user {$user['email']}: {$e->getMessage()}\n";            } catch (PDOException $e) {                echo "  ✓ Created/Updated user: {$user['email']} ({$user['role']})\n";                $stmt->execute($user);            try {        foreach ($users as $user) {                ");                status = VALUES(status)                role = VALUES(role),                password = VALUES(password),                name = VALUES(name),            ON DUPLICATE KEY UPDATE             VALUES (:name, :email, :password, :role, :status)            INSERT INTO users (name, email, password, role, status)         $stmt = $this->db->prepare("                ];            ]                'status' => 'active'                'role' => 'user',                'password' => password_hash('user123', PASSWORD_DEFAULT),                'email' => 'user@xeroclaro.com',                'name' => 'John Doe',            [            ],                'status' => 'active'                'role' => 'staff',                'password' => password_hash('staff123', PASSWORD_DEFAULT),                'email' => 'staff@xeroclaro.com',                'name' => 'Staff Member',            [            ],                'status' => 'active'                'role' => 'admin',                'password' => password_hash('admin123', PASSWORD_DEFAULT),                'email' => 'admin@xeroclaro.com',                'name' => 'Administrator',            [        $users = [                echo "Seeding users...\n";    private function seedUsers() {     */     * Seed users table with admin, staff, and user accounts    /**        }        echo "\n✅ Database seeding completed successfully!\n";                $this->seedServices();        $this->seedUsers();                echo "Starting database seeding...\n\n";    public function run() {     */     * Run all seeders    /**        }        $this->db = Database::getInstance()->getConnection();    public function __construct() {        private $db;class Seeder {require_once __DIR__ . '/../config/config.php'; */ * php database/seeder.php * Run this file once to seed the database: *  * Creates default admin, staff, and user accounts * Database Seeder/**/**
 * Database Seeder
 * Run this script to seed the database with initial data
 * 
 * Usage: php database/seeder.php
 * Or access via browser: http://localhost/xero_claro/database/seeder.php
 */

require_once __DIR__ . '/../config/config.php';

// Security check - remove or modify this in production
$allowedIPs = ['127.0.0.1', '::1', 'localhost'];
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'cli';

if (php_sapi_name() !== 'cli' && !in_array($clientIP, $allowedIPs)) {
    die('Access denied. This script can only be run locally.');
}

echo "<pre>";
echo "====================================\n";
echo "    CleanMoto Database Seeder\n";
echo "====================================\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Begin transaction
    $db->beginTransaction();
    
    // =====================================================
    // SEED USERS
    // =====================================================
    echo "Seeding Users...\n";
    
    // Default password for all seeded users: 'password'
    $hashedPassword = password_hash('password', PASSWORD_DEFAULT);
    
    $users = [
        [
            'name' => 'Administrator',
            'email' => 'admin@xeroclaro.com',
            'password' => $hashedPassword,
            'role' => 'admin',
            'status' => 'active'
        ],
        [
            'name' => 'John Staff',
            'email' => 'staff@xeroclaro.com',
            'password' => $hashedPassword,
            'role' => 'staff',
            'status' => 'active'
        ],
        [
            'name' => 'Jane Staff',
            'email' => 'jane.staff@xeroclaro.com',
            'password' => $hashedPassword,
            'role' => 'staff',
            'status' => 'active'
        ],
        [
            'name' => 'Regular User',
            'email' => 'user@xeroclaro.com',
            'password' => $hashedPassword,
            'role' => 'user',
            'status' => 'active'
        ],
        [
            'name' => 'Mike Customer',
            'email' => 'mike@example.com',
            'password' => $hashedPassword,
            'role' => 'user',
            'status' => 'active'
        ],
        [
            'name' => 'Sarah Customer',
            'email' => 'sarah@example.com',
            'password' => $hashedPassword,
            'role' => 'user',
            'status' => 'active'
        ]
    ];
    
    // Clear existing users (optional - comment out if you want to keep existing data)
    $db->exec("DELETE FROM activity_logs");
    $db->exec("DELETE FROM password_resets");
    $db->exec("DELETE FROM users");
    $db->exec("ALTER TABLE users AUTO_INCREMENT = 1");
    
    $userStmt = $db->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
    
    foreach ($users as $user) {
        // Check if user already exists
        $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$user['email']]);
        
        if (!$checkStmt->fetch()) {
            $userStmt->execute([
                $user['name'],
                $user['email'],
                $user['password'],
                $user['role'],
                $user['status']
            ]);
            echo "  ✓ Created user: {$user['name']} ({$user['email']}) - Role: {$user['role']}\n";
        } else {
            echo "  - User already exists: {$user['email']}\n";
        }
    }
    
    echo "\n";
    
    // =====================================================
    // SEED SERVICES
    // =====================================================
    echo "Seeding Services...\n";
    
    // Clear existing services
    $db->exec("DELETE FROM services");
    $db->exec("ALTER TABLE services AUTO_INCREMENT = 1");
    
    $services = [
        [
            'label' => 'haircut',
            'name' => 'Classic Haircut',
            'price' => 25.00,
            'duration' => 30
        ],
        [
            'label' => 'haircut',
            'name' => 'Premium Haircut & Style',
            'price' => 45.00,
            'duration' => 45
        ],
        [
            'label' => 'coloring',
            'name' => 'Full Hair Coloring',
            'price' => 85.00,
            'duration' => 120
        ],
        [
            'label' => 'coloring',
            'name' => 'Highlights',
            'price' => 95.00,
            'duration' => 90
        ],
        [
            'label' => 'coloring',
            'name' => 'Balayage',
            'price' => 150.00,
            'duration' => 150
        ],
        [
            'label' => 'styling',
            'name' => 'Blow Dry & Style',
            'price' => 35.00,
            'duration' => 30
        ],
        [
            'label' => 'styling',
            'name' => 'Special Occasion Styling',
            'price' => 75.00,
            'duration' => 60
        ],
        [
            'label' => 'treatment',
            'name' => 'Deep Conditioning Treatment',
            'price' => 40.00,
            'duration' => 45
        ],
        [
            'label' => 'treatment',
            'name' => 'Keratin Treatment',
            'price' => 200.00,
            'duration' => 180
        ],
        [
            'label' => 'treatment',
            'name' => 'Scalp Treatment',
            'price' => 50.00,
            'duration' => 30
        ],
        [
            'label' => 'beard',
            'name' => 'Beard Trim',
            'price' => 15.00,
            'duration' => 15
        ],
        [
            'label' => 'beard',
            'name' => 'Beard Shaping & Style',
            'price' => 25.00,
            'duration' => 25
        ]
    ];
    
    $serviceStmt = $db->prepare("INSERT INTO services (label, name, price, duration, status) VALUES (?, ?, ?, ?, 'active')");
    
    foreach ($services as $service) {
        $serviceStmt->execute([
            $service['label'],
            $service['name'],
            $service['price'],
            $service['duration']
        ]);
        echo "  ✓ Created service: {$service['name']} (\${$service['price']}, {$service['duration']} mins)\n";
    }
    
    echo "\n";
    
    // =====================================================
    // SEED SETTINGS (Optional)
    // =====================================================
    echo "Seeding Settings...\n";
    
    $settings = [
        ['key' => 'site_name', 'value' => 'Xero Claro'],
        ['key' => 'site_email', 'value' => 'contact@xeroclaro.com'],
        ['key' => 'site_phone', 'value' => '+1 (555) 123-4567'],
        ['key' => 'business_hours', 'value' => 'Mon-Sat: 9AM - 7PM'],
        ['key' => 'currency', 'value' => 'USD'],
        ['key' => 'booking_interval', 'value' => '30']
    ];
    
    $settingStmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    
    foreach ($settings as $setting) {
        $settingStmt->execute([$setting['key'], $setting['value']]);
        echo "  ✓ Set {$setting['key']}: {$setting['value']}\n";
    }
    
    // Commit transaction
    $db->commit();
    
    echo "\n====================================\n";
    echo "    Seeding Completed Successfully!\n";
    echo "====================================\n\n";
    
    echo "Default Login Credentials:\n";
    echo "----------------------------\n";
    echo "Admin:  admin@xeroclaro.com / password\n";
    echo "Staff:  staff@xeroclaro.com / password\n";
    echo "User:   user@xeroclaro.com / password\n";
    echo "\n";
    
} catch (PDOException $e) {
    // Rollback on error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "</pre>";
?>
