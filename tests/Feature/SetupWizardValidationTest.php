<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class SetupWizardValidationTest extends LaravelTestCase
{
    /**
     * Placeholder feature test. SetupWizard validation is covered end-to-end by unit tests
     * against PricingConfigValidator + the write-time wiring in ConfigOptionSetupService.
     *
     * Unskip when this extension's test harness can boot the full Filament 4 panel/action
     * lifecycle (action invocation, notification sink, halt semantics). Tracked alongside plan
     * file: .sisyphus/plans/dp-06-pricing-config-validation.md (Testing — Feature section).
     */
    public function test_setup_wizard_validation_feature_is_skipped_pending_filament_panel_boot_support(): void
    {
        // TODO dp-13: implement full Filament action lifecycle E2E test for SetupWizard pricing validation
        $this->markTestSkipped(
            'SetupWizard validation is covered by Unit tests. Unskip once the harness boots a full Filament 4 panel/action lifecycle (tracked: .sisyphus/plans/dp-06-pricing-config-validation.md).'
        );
    }
}
