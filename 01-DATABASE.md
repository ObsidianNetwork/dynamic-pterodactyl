# Database Schema

> **Related docs**: [02-SERVICES.md](02-SERVICES.md) (services that use these tables)

---

## Tables Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                  ptero_resource_reservations                     │
├─────────────────────────────────────────────────────────────────┤
│  Temporary holds on resources during checkout (15-min TTL)      │
│  Links cart items → reserved resources → specific nodes         │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                     ptero_pricing_configs                        │
├─────────────────────────────────────────────────────────────────┤
│  Per-product pricing configuration                               │
│  Slider limits, step sizes, pricing model parameters            │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                       ptero_audit_logs                           │
├─────────────────────────────────────────────────────────────────┤
│  Tracks configuration changes for accountability                │
│  Who changed what, when, and previous values                    │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                      ptero_alert_configs                         │
├─────────────────────────────────────────────────────────────────┤
│  Alert thresholds and notification preferences                  │
│  Per-location capacity warnings                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Migration 1: Resource Reservations

**File**: `2025_01_01_000001_create_ptero_resource_reservations_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_resource_reservations', function (Blueprint $table) {
            $table->id();
            
            // Unique token for tracking reservation
            $table->string('token', 64)->unique();
            
            // Link to cart (nullable - cleared after checkout)
            $table->unsignedBigInteger('cart_item_id')->nullable();
            
            // Link to service (set after provisioning)
            $table->unsignedBigInteger('service_id')->nullable();
            
            // Link to user for tracking
            $table->unsignedBigInteger('user_id')->nullable();
            
            // Pterodactyl references
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('location_id');
            
            // Reserved resources (all in MB except CPU)
            $table->unsignedInteger('memory');        // MB
            $table->unsignedBigInteger('disk');       // MB
            $table->unsignedInteger('cpu');           // Percentage (100 = 1 core)
            
            // Pricing snapshot at reservation time
            $table->decimal('calculated_price', 10, 2);
            $table->json('pricing_breakdown');
            
            // Status tracking
            $table->enum('status', [
                'pending',      // Cart item exists, awaiting payment
                'confirmed',    // Payment received, server created
                'expired',      // TTL exceeded without payment
                'cancelled',    // User removed from cart
                'released'      // Resources released back to pool
            ])->default('pending');
            
            // Admin notes
            $table->text('admin_notes')->nullable();
            
            // Timestamps
            $table->timestamp('expires_at');
            $table->timestamps();
            
            // Indexes for efficient queries
            $table->index(['node_id', 'status', 'expires_at'], 'idx_node_pending');
            $table->index(['cart_item_id']);
            $table->index(['status', 'expires_at'], 'idx_cleanup');
            $table->index(['location_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['created_at']);
            
            // Foreign keys
            $table->foreign('cart_item_id')
                  ->references('id')
                  ->on('cart_items')
                  ->onDelete('set null');
                  
            $table->foreign('service_id')
                  ->references('id')
                  ->on('services')
                  ->onDelete('set null');
                  
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_resource_reservations');
    }
};
```

### Reservation Status Flow

```
┌─────────┐    cart item    ┌─────────┐    payment    ┌───────────┐
│ (none)  │ ──────────────▶ │ pending │ ────────────▶ │ confirmed │
└─────────┘                 └─────────┘               └───────────┘
                                 │
                    ┌────────────┼────────────┐
                    ▼            ▼            ▼
               ┌─────────┐ ┌───────────┐ ┌──────────┐
               │ expired │ │ cancelled │ │ released │
               │ (TTL)   │ │ (user)    │ │ (admin)  │
               └─────────┘ └───────────┘ └──────────┘
```

---

## Migration 2: Pricing Configs

**File**: `2025_01_01_000002_create_ptero_pricing_configs_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_pricing_configs', function (Blueprint $table) {
            $table->id();
            
            // Link to Paymenter product
            $table->unsignedBigInteger('product_id')->unique();
            
            // Pricing model selection
            $table->enum('pricing_model', [
                'linear',
                'tiered',
                'base_plus_addon'
            ])->default('linear');
            
            // JSON configuration for pricing model
            // Structure varies by model - see 07-PRICING-MODELS.md
            $table->json('pricing_config');
            
            // Memory slider configuration (stored in MB)
            $table->unsignedInteger('min_memory')->default(1024);      // 1GB
            $table->unsignedInteger('max_memory')->default(65536);     // 64GB
            $table->unsignedInteger('memory_step')->default(1024);     // 1GB steps
            $table->unsignedInteger('default_memory')->default(4096);  // 4GB default
            
            // CPU slider configuration (percentage: 100 = 1 core)
            $table->unsignedInteger('min_cpu')->default(100);          // 1 core
            $table->unsignedInteger('max_cpu')->default(800);          // 8 cores
            $table->unsignedInteger('cpu_step')->default(100);         // 1 core steps
            $table->unsignedInteger('default_cpu')->default(200);      // 2 cores default
            
            // Disk slider configuration (stored in MB)
            $table->unsignedInteger('min_disk')->default(10240);       // 10GB
            $table->unsignedInteger('max_disk')->default(512000);      // 500GB
            $table->unsignedInteger('disk_step')->default(10240);      // 10GB steps
            $table->unsignedInteger('default_disk')->default(51200);   // 50GB default
            
            // Feature toggles
            $table->boolean('enable_memory_slider')->default(true);
            $table->boolean('enable_cpu_slider')->default(true);
            $table->boolean('enable_disk_slider')->default(true);
            $table->boolean('is_active')->default(true);
            
            // Customer display customization (labels, tooltips)
            $table->json('display_config')->nullable();
            
            // Location restrictions (null = all locations allowed)
            $table->json('allowed_locations')->nullable();
            
            $table->timestamps();
            
            // Foreign key
            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_pricing_configs');
    }
};
```

### Display Config JSON Structure

```json
{
    "memory_label": "RAM",
    "cpu_label": "CPU Cores", 
    "disk_label": "Storage",
    "memory_tooltip": "RAM determines how much data your server can hold...",
    "cpu_tooltip": "CPU cores determine processing power...",
    "disk_tooltip": "Storage space for your server files...",
    "price_format": "monthly",
    "show_breakdown": true,
    "show_savings_badge": true
}
```

---

## Migration 3: Audit Logs

**File**: `2025_01_01_000003_create_ptero_audit_logs_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_audit_logs', function (Blueprint $table) {
            $table->id();
            
            // Who made the change
            $table->unsignedBigInteger('user_id');
            $table->string('user_name');
            $table->string('user_email');
            
            // What was changed
            $table->string('action'); // created, updated, deleted, cancelled
            $table->string('entity_type'); // pricing_config, reservation, alert_config
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_name')->nullable(); // Product name, etc.
            
            // Change details
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('description')->nullable();
            
            // Request context
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            
            $table->timestamp('created_at');
            
            // Indexes
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id']);
            $table->index(['created_at']);
            $table->index(['action']);
            
            // Foreign key
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_audit_logs');
    }
};
```

---

## Migration 4: Alert Configs

**File**: `2025_01_01_000004_create_ptero_alert_configs_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_alert_configs', function (Blueprint $table) {
            $table->id();
            
            // Scope: global (null) or per-location
            $table->unsignedInteger('location_id')->nullable();
            $table->string('location_name')->nullable();
            
            // Capacity thresholds (percentage)
            $table->unsignedTinyInteger('memory_warning_threshold')->default(80);
            $table->unsignedTinyInteger('memory_critical_threshold')->default(95);
            $table->unsignedTinyInteger('disk_warning_threshold')->default(80);
            $table->unsignedTinyInteger('disk_critical_threshold')->default(95);
            
            // Notification settings
            $table->boolean('email_notifications')->default(true);
            $table->json('notification_emails')->nullable(); // Array of emails
            $table->boolean('webhook_notifications')->default(false);
            $table->string('webhook_url')->nullable();
            
            // Cooldown to prevent spam
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->timestamp('last_notification_at')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Indexes
            $table->index(['location_id']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_alert_configs');
    }
};
```

---

## Eloquent Models

### PricingConfig Model

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingConfig extends Model
{
    protected $table = 'ptero_pricing_configs';
    
    protected $fillable = [
        'product_id',
        'pricing_model',
        'pricing_config',
        'min_memory', 'max_memory', 'memory_step', 'default_memory',
        'min_cpu', 'max_cpu', 'cpu_step', 'default_cpu',
        'min_disk', 'max_disk', 'disk_step', 'default_disk',
        'enable_memory_slider', 'enable_cpu_slider', 'enable_disk_slider',
        'is_active',
        'display_config',
        'allowed_locations',
    ];
    
    protected $casts = [
        'pricing_config' => 'array',
        'display_config' => 'array',
        'allowed_locations' => 'array',
        'enable_memory_slider' => 'boolean',
        'enable_cpu_slider' => 'boolean',
        'enable_disk_slider' => 'boolean',
        'is_active' => 'boolean',
    ];
    
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class);
    }
}
```

### ResourceReservation Model

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceReservation extends Model
{
    protected $table = 'ptero_resource_reservations';
    
    protected $fillable = [
        'token', 'cart_item_id', 'service_id', 'user_id',
        'node_id', 'location_id',
        'memory', 'cpu', 'disk',
        'calculated_price', 'pricing_breakdown',
        'status', 'admin_notes', 'expires_at',
    ];
    
    protected $casts = [
        'pricing_breakdown' => 'array',
        'expires_at' => 'datetime',
        'calculated_price' => 'decimal:2',
    ];
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
    
    public function service(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Service::class);
    }
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending')
                     ->where('expires_at', '>', now());
    }
    
    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
                     ->where('expires_at', '<=', now());
    }
}
```

### AuditLog Model

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'ptero_audit_logs';
    
    public $timestamps = false;
    
    protected $fillable = [
        'user_id', 'user_name', 'user_email',
        'action', 'entity_type', 'entity_id', 'entity_name',
        'old_values', 'new_values', 'description',
        'ip_address', 'user_agent', 'created_at',
    ];
    
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];
}
```

### AlertConfig Model

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

class AlertConfig extends Model
{
    protected $table = 'ptero_alert_configs';
    
    protected $fillable = [
        'location_id', 'location_name',
        'memory_warning_threshold', 'memory_critical_threshold',
        'disk_warning_threshold', 'disk_critical_threshold',
        'email_notifications', 'notification_emails',
        'webhook_notifications', 'webhook_url',
        'cooldown_minutes', 'last_notification_at',
        'is_active',
    ];
    
    protected $casts = [
        'notification_emails' => 'array',
        'email_notifications' => 'boolean',
        'webhook_notifications' => 'boolean',
        'is_active' => 'boolean',
        'last_notification_at' => 'datetime',
    ];
    
    public function scopeGlobal($query)
    {
        return $query->whereNull('location_id');
    }
    
    public function scopeForLocation($query, int $locationId)
    {
        return $query->where('location_id', $locationId);
    }
}
```

---

## Index Strategy

| Table | Index | Purpose |
|-------|-------|---------|
| reservations | `idx_node_pending` | Fast lookup of pending reservations by node |
| reservations | `idx_cleanup` | Efficient expired reservation cleanup |
| reservations | `location_id, status` | Location availability calculations |
| pricing_configs | `product_id` (unique) | One config per product |
| audit_logs | `entity_type, entity_id` | View history for specific entity |
| audit_logs | `created_at` | Time-based filtering |
| alert_configs | `location_id` | Per-location alert lookup |

---

## Data Relationships

```
products (Paymenter)
    │
    └──< ptero_pricing_configs (1:1)
    
users (Paymenter)
    │
    ├──< ptero_resource_reservations (1:many)
    └──< ptero_audit_logs (1:many)
    
cart_items (Paymenter)
    │
    └──< ptero_resource_reservations (1:1 while in cart)
    
services (Paymenter)
    │
    └──< ptero_resource_reservations (1:1 after confirmation)
```
