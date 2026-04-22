<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class SetupWizardValidationTest extends LaravelTestCase
{
    public function test_setup_wizard_validation_feature_is_skipped_pending_filament_panel_boot_support(): void
    {
        $this->markTestSkipped(
            'SetupWizard validation is currently exercised via unit coverage because this extension test harness does not boot the full Filament 4 panel/action lifecycle needed to submit the page action reliably.'
        );
    }
}
