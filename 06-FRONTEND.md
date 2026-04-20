# Frontend Implementation

> **Related docs**: [03-API.md](03-API.md) (endpoints called), [07-PRICING-MODELS.md](07-PRICING-MODELS.md) (price display)

---

## Overview

The frontend enhances Paymenter's product page with:
- Resource sliders (noUiSlider)
- Real-time pricing updates
- Availability-limited maximums
- Responsive design

**Injection method**: JavaScript added via Paymenter's `head` event hook.

---

## Head Scripts View

**File**: `resources/views/head-scripts.blade.php`

```blade
{{-- noUiSlider CDN --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.css">

<script>
window.DynamicPterodactyl = (function() {
    'use strict';
    
    // ============================================
    // CONFIGURATION
    // ============================================
    
    const CONFIG = {
        apiBase: '/api/dynamic-pterodactyl',
        debounceMs: 300,
        currency: '{{ config("app.currency", "USD") }}',
        currencySymbol: '{{ config("app.currency_symbol", "$") }}',
    };
    
    // ============================================
    // STATE
    // ============================================
    
    let state = {
        productId: null,
        locationId: null,
        pricingConfig: null,
        availability: null,
        currentPrice: 0,
        sliders: {},
    };
    
    // ============================================
    // UTILITIES
    // ============================================
    
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }
    
    function formatMemory(mb) {
        return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : mb + ' MB';
    }
    
    function formatDisk(mb) {
        return mb >= 1024 ? Math.round(mb / 1024) + ' GB' : mb + ' MB';
    }
    
    function formatCpu(percent) {
        const cores = percent / 100;
        return cores === 1 ? '1 Core' : cores + ' Cores';
    }
    
    function formatPrice(amount) {
        return CONFIG.currencySymbol + parseFloat(amount).toFixed(2);
    }
    
    // ============================================
    // API CALLS
    // ============================================
    
    async function fetchAvailability(locationId) {
        const response = await fetch(`${CONFIG.apiBase}/availability/${locationId}`);
        if (!response.ok) throw new Error('Failed to fetch availability');
        return response.json();
    }
    
    async function fetchPricingConfig(productId) {
        const response = await fetch(`${CONFIG.apiBase}/pricing/config/${productId}`);
        if (!response.ok) throw new Error('Failed to fetch pricing config');
        return response.json();
    }
    
    async function calculatePrice(productId, resources) {
        const response = await fetch(`${CONFIG.apiBase}/pricing/calculate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            body: JSON.stringify({
                product_id: productId,
                memory: resources.memory,
                cpu: resources.cpu,
                disk: resources.disk,
            }),
        });
        if (!response.ok) throw new Error('Failed to calculate price');
        return response.json();
    }
    
    // ============================================
    // SLIDER CREATION
    // ============================================
    
    function createSlider(inputElement, config) {
        // Create wrapper structure
        const wrapper = document.createElement('div');
        wrapper.className = 'dynamic-ptero-slider-wrapper';
        
        // Label row with value display
        const labelRow = document.createElement('div');
        labelRow.className = 'dynamic-ptero-label-row';
        labelRow.innerHTML = `
            <span class="dynamic-ptero-label">${config.label || ''}</span>
            <span class="dynamic-ptero-value"></span>
        `;
        
        // Slider container
        const sliderContainer = document.createElement('div');
        sliderContainer.className = 'dynamic-ptero-slider';
        
        // Range info
        const rangeInfo = document.createElement('div');
        rangeInfo.className = 'dynamic-ptero-range';
        rangeInfo.innerHTML = `
            <span>${config.formatter(config.min)}</span>
            <span>${config.formatter(config.max)}</span>
        `;
        
        // Hide original input and insert wrapper
        inputElement.style.display = 'none';
        inputElement.parentNode.insertBefore(wrapper, inputElement);
        wrapper.appendChild(labelRow);
        wrapper.appendChild(sliderContainer);
        wrapper.appendChild(rangeInfo);
        
        const valueDisplay = labelRow.querySelector('.dynamic-ptero-value');
        
        // Initialize noUiSlider
        const slider = noUiSlider.create(sliderContainer, {
            start: [parseInt(inputElement.value) || config.default || config.min],
            connect: [true, false],
            step: config.step,
            range: {
                'min': config.min,
                'max': config.max
            },
            format: {
                to: (value) => Math.round(value),
                from: (value) => Math.round(value),
            },
            tooltips: false,
        });
        
        // Update on slide
        slider.on('update', (values) => {
            const value = parseInt(values[0]);
            inputElement.value = value;
            valueDisplay.textContent = config.formatter(value);
            
            // Trigger change event for form handling
            inputElement.dispatchEvent(new Event('change', { bubbles: true }));
        });
        
        return { slider, wrapper, rangeInfo, valueDisplay };
    }
    
    function updateSliderLimits(sliderObj, min, max, formatter) {
        if (max <= min) {
            max = min + 1; // Prevent invalid range
        }
        
        sliderObj.slider.updateOptions({
            range: { 'min': min, 'max': max },
        });
        
        sliderObj.rangeInfo.innerHTML = `
            <span>${formatter(min)}</span>
            <span>${formatter(max)}</span>
        `;
    }
    
    // ============================================
    // PRICE UPDATE
    // ============================================
    
    const updatePrice = debounce(async function(inputs, priceDisplay) {
        if (!state.productId) return;
        
        const resources = {
            memory: parseInt(inputs.memory?.value) || state.pricingConfig?.sliders.memory.default || 1024,
            cpu: parseInt(inputs.cpu?.value) || state.pricingConfig?.sliders.cpu.default || 100,
            disk: parseInt(inputs.disk?.value) || state.pricingConfig?.sliders.disk.default || 10240,
        };
        
        try {
            priceDisplay?.classList.add('loading');
            
            const result = await calculatePrice(state.productId, resources);
            
            if (result.success) {
                state.currentPrice = result.data.total;
                
                if (priceDisplay) {
                    // Build breakdown HTML
                    const breakdown = result.data.breakdown
                        .filter(item => item.amount > 0)
                        .map(item => `<div class="breakdown-item">
                            <span>${item.label}</span>
                            <span>${formatPrice(item.amount)}</span>
                        </div>`)
                        .join('');
                    
                    priceDisplay.innerHTML = `
                        <div class="dynamic-ptero-price-total">
                            ${formatPrice(result.data.total)}
                            <span class="period">/mo</span>
                        </div>
                        ${breakdown ? `<div class="dynamic-ptero-price-breakdown">${breakdown}</div>` : ''}
                    `;
                    
                    // Animate update
                    priceDisplay.classList.add('updated');
                    setTimeout(() => priceDisplay.classList.remove('updated'), 300);
                }
            }
        } catch (error) {
            console.error('Price calculation failed:', error);
        } finally {
            priceDisplay?.classList.remove('loading');
        }
    }, CONFIG.debounceMs);
    
    // ============================================
    // INITIALIZATION
    // ============================================
    
    async function init(productId) {
        state.productId = productId;
        
        // Find configurable option inputs
        const memoryInput = document.querySelector('[name="configoptions[memory]"]');
        const cpuInput = document.querySelector('[name="configoptions[cpu]"]');
        const diskInput = document.querySelector('[name="configoptions[disk]"]');
        const locationSelect = document.querySelector('[name="configoptions[location]"]');
        
        // Exit if no relevant inputs found
        if (!memoryInput && !cpuInput && !diskInput) {
            console.debug('DynamicPterodactyl: No resource inputs found');
            return;
        }
        
        // Fetch pricing configuration
        try {
            const configResult = await fetchPricingConfig(productId);
            if (!configResult.success) {
                console.warn('DynamicPterodactyl: Product not configured', configResult);
                return;
            }
            state.pricingConfig = configResult.data;
        } catch (error) {
            console.error('DynamicPterodactyl: Failed to fetch config', error);
            return;
        }
        
        // Create price display widget
        const priceDisplay = document.createElement('div');
        priceDisplay.className = 'dynamic-ptero-price-display';
        priceDisplay.innerHTML = '<div class="dynamic-ptero-price-total">Calculating...</div>';
        
        // Insert price display before submit button
        const form = (memoryInput || cpuInput || diskInput)?.closest('form');
        if (form) {
            const submitButton = form.querySelector('[type="submit"], button:not([type])');
            if (submitButton) {
                submitButton.parentNode.insertBefore(priceDisplay, submitButton);
            } else {
                form.appendChild(priceDisplay);
            }
        }
        
        // Store inputs for later
        const inputs = { memory: memoryInput, cpu: cpuInput, disk: diskInput };
        
        // Create sliders
        const displayConfig = state.pricingConfig.display || {};
        
        if (memoryInput && state.pricingConfig.sliders.memory.enabled) {
            state.sliders.memory = createSlider(memoryInput, {
                ...state.pricingConfig.sliders.memory,
                label: displayConfig.memory_label || 'RAM',
                formatter: formatMemory,
            });
        }
        
        if (cpuInput && state.pricingConfig.sliders.cpu.enabled) {
            state.sliders.cpu = createSlider(cpuInput, {
                ...state.pricingConfig.sliders.cpu,
                label: displayConfig.cpu_label || 'CPU',
                formatter: formatCpu,
            });
        }
        
        if (diskInput && state.pricingConfig.sliders.disk.enabled) {
            state.sliders.disk = createSlider(diskInput, {
                ...state.pricingConfig.sliders.disk,
                label: displayConfig.disk_label || 'Storage',
                formatter: formatDisk,
            });
        }
        
        // Location change handler
        if (locationSelect) {
            locationSelect.addEventListener('change', async (e) => {
                state.locationId = e.target.value;
                if (!state.locationId) return;
                
                try {
                    const availResult = await fetchAvailability(state.locationId);
                    
                    if (availResult.success) {
                        state.availability = availResult.data;
                        
                        // Update slider maximums based on availability
                        if (state.sliders.memory) {
                            const maxMem = Math.min(
                                availResult.data.max_memory,
                                state.pricingConfig.sliders.memory.max
                            );
                            updateSliderLimits(
                                state.sliders.memory,
                                state.pricingConfig.sliders.memory.min,
                                maxMem,
                                formatMemory
                            );
                        }
                        
                        if (state.sliders.cpu) {
                            const maxCpu = Math.min(
                                availResult.data.max_cpu,
                                state.pricingConfig.sliders.cpu.max
                            );
                            updateSliderLimits(
                                state.sliders.cpu,
                                state.pricingConfig.sliders.cpu.min,
                                maxCpu,
                                formatCpu
                            );
                        }
                        
                        if (state.sliders.disk) {
                            const maxDisk = Math.min(
                                availResult.data.max_disk,
                                state.pricingConfig.sliders.disk.max
                            );
                            updateSliderLimits(
                                state.sliders.disk,
                                state.pricingConfig.sliders.disk.min,
                                maxDisk,
                                formatDisk
                            );
                        }
                        
                        // Warn if limited availability
                        if (!availResult.data.has_capacity) {
                            showAvailabilityWarning('Limited availability in this location');
                        }
                    }
                } catch (error) {
                    console.error('Failed to fetch availability:', error);
                }
                
                // Update price after location change
                updatePrice(inputs, priceDisplay);
            });
            
            // Trigger initial location load if already selected
            if (locationSelect.value) {
                locationSelect.dispatchEvent(new Event('change'));
            }
        }
        
        // Price update on any slider change
        [memoryInput, cpuInput, diskInput].forEach(input => {
            if (input) {
                input.addEventListener('change', () => updatePrice(inputs, priceDisplay));
            }
        });
        
        // Initial price calculation
        updatePrice(inputs, priceDisplay);
    }
    
    function showAvailabilityWarning(message) {
        // Create or update warning element
        let warning = document.querySelector('.dynamic-ptero-availability-warning');
        if (!warning) {
            warning = document.createElement('div');
            warning.className = 'dynamic-ptero-availability-warning';
            document.querySelector('.dynamic-ptero-price-display')?.before(warning);
        }
        warning.innerHTML = `⚠️ ${message}`;
        warning.style.display = 'block';
    }
    
    // Public API
    return { init, state };
})();

// Auto-initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const productIdMeta = document.querySelector('meta[name="product-id"]');
    if (productIdMeta) {
        DynamicPterodactyl.init(parseInt(productIdMeta.content));
    }
});
</script>
```

---

## CSS Styles

```blade
<style>
/* ============================================
   SLIDER WRAPPER
   ============================================ */

.dynamic-ptero-slider-wrapper {
    margin: 1.5rem 0;
    padding: 0.5rem 0;
}

.dynamic-ptero-label-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.dynamic-ptero-label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary, #374151);
}

.dynamic-ptero-value {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--primary, #3b82f6);
}

.dynamic-ptero-slider {
    margin-bottom: 0.5rem;
}

.dynamic-ptero-range {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: var(--text-secondary, #6b7280);
}

/* ============================================
   PRICE DISPLAY
   ============================================ */

.dynamic-ptero-price-display {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.5rem;
    margin: 1.5rem 0;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.dynamic-ptero-price-display.loading {
    opacity: 0.7;
}

.dynamic-ptero-price-display.updated {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.dynamic-ptero-price-total {
    font-size: 2rem;
    font-weight: 800;
    color: var(--primary, #3b82f6);
    line-height: 1;
}

.dynamic-ptero-price-total .period {
    font-size: 1rem;
    font-weight: 400;
    opacity: 0.7;
}

.dynamic-ptero-price-breakdown {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
}

.dynamic-ptero-price-breakdown .breakdown-item {
    display: flex;
    justify-content: space-between;
    font-size: 0.875rem;
    color: var(--text-secondary, #6b7280);
    padding: 0.25rem 0;
}

/* ============================================
   AVAILABILITY WARNING
   ============================================ */

.dynamic-ptero-availability-warning {
    background: #fef3c7;
    border: 1px solid #fbbf24;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    margin-bottom: 1rem;
    font-size: 0.875rem;
    color: #92400e;
    display: none;
}

/* ============================================
   noUiSlider CUSTOM STYLING
   ============================================ */

.noUi-target {
    background: #e5e7eb;
    border: none;
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
    border-radius: 6px;
}

.noUi-connect {
    background: linear-gradient(90deg, #3b82f6, #2563eb);
    border-radius: 6px;
}

.noUi-horizontal {
    height: 10px;
}

.noUi-horizontal .noUi-handle {
    width: 22px;
    height: 22px;
    top: -7px;
    right: -11px;
    border-radius: 50%;
    background: white;
    border: 3px solid #3b82f6;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    cursor: grab;
    transition: transform 0.1s ease;
}

.noUi-horizontal .noUi-handle:hover {
    transform: scale(1.1);
}

.noUi-horizontal .noUi-handle:active {
    cursor: grabbing;
    transform: scale(1.15);
}

.noUi-handle:before,
.noUi-handle:after {
    display: none;
}

/* Focus state for accessibility */
.noUi-handle:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}

/* ============================================
   DARK MODE SUPPORT
   ============================================ */

@media (prefers-color-scheme: dark) {
    .dynamic-ptero-price-display {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border-color: #334155;
    }
    
    .dynamic-ptero-label {
        color: #e2e8f0;
    }
    
    .dynamic-ptero-range {
        color: #94a3b8;
    }
    
    .dynamic-ptero-price-breakdown .breakdown-item {
        color: #94a3b8;
    }
    
    .dynamic-ptero-price-breakdown {
        border-top-color: #334155;
    }
    
    .noUi-target {
        background: #334155;
    }
}

/* ============================================
   RESPONSIVE
   ============================================ */

@media (max-width: 640px) {
    .dynamic-ptero-price-total {
        font-size: 1.5rem;
    }
    
    .dynamic-ptero-slider-wrapper {
        margin: 1rem 0;
    }
}
</style>
```

---

## Integration Notes

### Adding Product ID Meta Tag

The frontend needs to know which product to fetch config for. Add this to product page templates:

```blade
{{-- In product view template --}}
<meta name="product-id" content="{{ $product->id }}">
```

Or inject via the extension:

```php
Event::listen('head', function () {
    $productId = request()->route('product')?->id;
    if ($productId) {
        return [
            'content' => "<meta name=\"product-id\" content=\"{$productId}\">",
        ];
    }
});
```

### CSRF Token

Ensure the CSRF token meta tag exists (usually in Laravel apps):

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
```

### Configurable Options Naming

The JavaScript looks for inputs with these exact names:
- `configoptions[memory]`
- `configoptions[cpu]`
- `configoptions[disk]`
- `configoptions[location]`

These should match your Paymenter configurable options.
