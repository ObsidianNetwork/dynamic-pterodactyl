# Pricing Models

> **Related docs**: [02-SERVICES.md](02-SERVICES.md) (SliderConfigReaderService), [05-ADMIN-UI.md](05-ADMIN-UI.md) (configuration forms)

---

## Overview

Paymenter core now owns `dynamic_slider` pricing math. This extension only stores slider metadata and previews pricing through the same core methods used by checkout and recalculation flows.

Three pricing models serve different business needs:

| Model | Best For | Example |
|-------|----------|---------|
| **Linear** | Simple per-unit pricing | $0.50/GB RAM, $2/core |
| **Tiered** | Volume discounts | First 8GB at $0.60, next 8GB at $0.45 |
| **Base + Addon** | Package upsells | $10 base includes 4GB, +$0.75/extra GB |

## Pricing ownership after dp-core-01

dp-core-01 shipped the missing core fixes. The extension now relies on:

- `Plan::dynamicSliderBasePrice()` for the one-time shared base charge per product/plan.
- `ConfigOption::calculateDynamicPriceDelta()` for per-slider marginal charges.
- `App\Rules\DynamicSliderPricingRule` for SetupWizard write-time validation.

The retired extension-only scaffolding (`PricingCalculatorService`, `PricingConfigValidator`) has been removed in dp-09.

---

## Model 1: Linear Pricing

Simplest model — flat rate per unit of each resource.

### Configuration JSON

```json
{
    "base_price": 5.00,
    "memory_per_gb": 0.50,
    "cpu_per_core": 2.00,
    "disk_per_gb": 0.02
}
```

### Calculation Formula

```
Total = base_price 
      + (memory_gb × memory_per_gb)
      + (cpu_cores × cpu_per_core)
      + (disk_gb × disk_per_gb)
```

### Example

**Config**: Base $5, Memory $0.50/GB, CPU $2/core, Disk $0.02/GB

**Selection**: 8GB RAM, 4 cores, 100GB disk

```
Base:     $5.00
Memory:   8 × $0.50  = $4.00
CPU:      4 × $2.00  = $8.00
Disk:     100 × $0.02 = $2.00
─────────────────────────────
Total:    $19.00/mo
```

### When to Use

- Simple, transparent pricing
- Resources are equally valuable at any quantity
- Easy for customers to understand

---

## Model 2: Tiered Pricing

Volume discounts at breakpoints — encourages larger purchases.

### Configuration JSON

```json
{
    "base_price": 0,
    "memory_tiers": [
        { "up_to_gb": 4, "per_gb": 1.00 },
        { "up_to_gb": 8, "per_gb": 0.75 },
        { "up_to_gb": 16, "per_gb": 0.50 },
        { "up_to_gb": null, "per_gb": 0.35 }
    ],
    "cpu_tiers": [
        { "up_to": 2, "rate": 3.00 },
        { "up_to": 4, "rate": 2.50 },
        { "up_to": null, "rate": 2.00 }
    ],
    "disk_tiers": [
        { "up_to_gb": 50, "per_gb": 0.03 },
        { "up_to_gb": 100, "per_gb": 0.025 },
        { "up_to_gb": null, "per_gb": 0.02 }
    ]
}
```

### Calculation Formula

For each resource, iterate through tiers:

```
cost = 0
remaining = amount

for each tier:
    tier_amount = min(remaining, tier.up_to - previous_tier.up_to)
    cost += tier_amount × tier.rate
    remaining -= tier_amount
    if remaining <= 0: break
```

### Example

**Memory Tiers**: 0-4GB @ $1.00, 4-8GB @ $0.75, 8-16GB @ $0.50, 16+GB @ $0.35

**Selection**: 12GB RAM

```
Tier 1: First 4GB   × $1.00 = $4.00
Tier 2: Next 4GB    × $0.75 = $3.00
Tier 3: Next 4GB    × $0.50 = $2.00
────────────────────────────────────
Memory Total:         $9.00

(vs. linear at $0.50/GB = $6.00 — tiered gives better margins on small orders)
```

### When to Use

- Reward larger purchases with discounts
- Protect margins on small orders
- Cloud-style pricing familiar to customers

### Admin UI Representation

```
┌────────────────────────────────────────────────────┐
│ Memory Tiers                              [+ Add]  │
├────────────────────────────────────────────────────┤
│ ┌──────────────────────────────────────────────┐   │
│ │ Up to: [4    ] GB    Rate: $[1.00  ]/GB  [×] │   │
│ └──────────────────────────────────────────────┘   │
│ ┌──────────────────────────────────────────────┐   │
│ │ Up to: [8    ] GB    Rate: $[0.75  ]/GB  [×] │   │
│ └──────────────────────────────────────────────┘   │
│ ┌──────────────────────────────────────────────┐   │
│ │ Up to: [16   ] GB    Rate: $[0.50  ]/GB  [×] │   │
│ └──────────────────────────────────────────────┘   │
│ ┌──────────────────────────────────────────────┐   │
│ │ Up to: [∞    ]       Rate: $[0.35  ]/GB  [×] │   │
│ └──────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────┘
```

---

## Model 3: Base + Addon

Package with included resources; charge only for overages.

`base_plus_addon` was a documentation alias; `base_addon` is the single canonical name as of dp-07 (2026-04-22).

### Configuration JSON

```json
{
    "base_price": 15.00,
    "included": {
        "memory_gb": 4,
        "cpu_cores": 2,
        "disk_gb": 50
    },
    "overage": {
        "memory_per_gb": 0.75,
        "cpu_per_core": 3.00,
        "disk_per_gb": 0.05
    }
}
```

### Calculation Formula

```
extra_memory = max(0, selected_memory - included.memory_gb)
extra_cpu = max(0, selected_cpu - included.cpu_cores)
extra_disk = max(0, selected_disk - included.disk_gb)

Total = base_price
      + (extra_memory × overage.memory_per_gb)
      + (extra_cpu × overage.cpu_per_core)
      + (extra_disk × overage.disk_per_gb)
```

### Example

**Base**: $15 includes 4GB RAM, 2 cores, 50GB disk  
**Overage**: $0.75/GB RAM, $3/core, $0.05/GB disk

**Selection**: 8GB RAM, 4 cores, 50GB disk

```
Base Package:           $15.00
Extra Memory: (8-4) × $0.75 = $3.00
Extra CPU:    (4-2) × $3.00 = $6.00
Extra Disk:   (50-50) × $0.05 = $0.00
─────────────────────────────────────
Total:                  $24.00/mo
```

### Customer Display

Show what's included vs. what's extra:

```
┌─────────────────────────────────────────┐
│ Starter Package              $15.00/mo  │
│ ─────────────────────────────────────── │
│ ✓ 4 GB RAM included                     │
│ ✓ 2 CPU Cores included                  │
│ ✓ 50 GB Storage included                │
├─────────────────────────────────────────┤
│ Your Add-ons:                           │
│   +4 GB RAM                    +$3.00   │
│   +2 CPU Cores                 +$6.00   │
├─────────────────────────────────────────┤
│ Total                         $24.00/mo │
└─────────────────────────────────────────┘
```

### When to Use

- Clear "starter" packages
- Upsell add-on resources
- Simpler decision for customers (start with package, add what you need)

---

## Database Storage

Slider pricing is read from ConfigOption metadata built by `Services/ConfigOptionSetupService` and consumed by Paymenter core pricing methods plus `Services/SliderConfigReaderService` for config reads. It is no longer stored in `ptero_pricing_configs.pricing_config`.

The `pricing_model` enum determines which calculation method to use:
- `linear` → `calculateLinear()`
- `tiered` → `calculateTiered()`
- `base_addon` → `calculateBasePlusAddon()`
---

## Form-to-JSON Conversion

The Filament admin UI uses flat form fields that get converted to JSON before saving.

### Linear Model Mapping

| Form Field | JSON Path |
|------------|-----------|
| `base_price` | `base_price` |
| `linear_memory_rate` | `memory_per_gb` |
| `linear_cpu_rate` | `cpu_per_core` |
| `linear_disk_rate` | `disk_per_gb` |

### Tiered Model Mapping

| Form Field | JSON Path |
|------------|-----------|
| `base_price` | `base_price` |
| `memory_tiers[0].up_to_gb` | `memory_tiers[0].up_to_gb` |
| `memory_tiers[0].per_gb` | `memory_tiers[0].per_gb` |
| (Repeater handles array) | |

### Base+Addon Model Mapping

| Form Field | JSON Path |
|------------|-----------|
| `base_price` | `base_price` |
| `included_memory` | `included.memory_gb` |
| `included_cpu` | `included.cpu_cores` |
| `included_disk` | `included.disk_gb` |
| `overage_memory` | `overage.memory_per_gb` |
| `overage_cpu` | `overage.cpu_per_core` |
| `overage_disk` | `overage.disk_per_gb` |

---

## Price Breakdown Response

All models return the same response structure:

```json
{
    "total": 24.00,
    "breakdown": [
        { "label": "Base Package", "amount": 15.00 },
        { "label": "Extra Memory (+4 GB)", "amount": 3.00 },
        { "label": "Extra CPU (+2 cores)", "amount": 6.00 }
    ],
    "model": "base_addon"
}
```

The frontend uses `breakdown` to show itemized pricing.

---

## Validation Rules

| Rule | Description |
|------|-------------|
| `base_price >= 0` | No negative base prices |
| `*_per_gb >= 0` | No negative rates |
| Tier `up_to` ascending | Each tier must be higher than previous |
| Final tier `up_to = null` | Last tier handles unlimited |
| `included.* >= 0` | Non-negative included amounts |

Validation happens in Filament form rules and again in `App\Rules\DynamicSliderPricingRule` via `ConfigOptionSetupService`.

---

## Adding a New Pricing Model

1. Add enum value to `pricing_model` in migration
2. Add/extend calculation support in Paymenter core pricing methods
3. Add form section in `PricingConfigResource`
4. Add conversion logic in `mutateFormDataBeforeSave()`
5. Update frontend price display if needed
