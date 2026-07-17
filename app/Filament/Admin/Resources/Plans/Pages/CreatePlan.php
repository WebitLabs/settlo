<?php

namespace App\Filament\Admin\Resources\Plans\Pages;

use App\Filament\Admin\Resources\Plans\PlanResource;
use App\Models\Plan;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    /**
     * Plans are written with forceFill for parity with the rest of the admin
     * surface, and every creation is recorded in the audit trail.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $plan = new Plan;
        $plan->forceFill($data)->save();

        app(AuditLogger::class)->log('plan.created', $plan, [
            'code' => $plan->code,
            'price_monthly' => (string) $plan->price_monthly,
        ]);

        return $plan;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
