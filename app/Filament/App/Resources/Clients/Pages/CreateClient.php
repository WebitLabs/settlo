<?php

namespace App\Filament\App\Resources\Clients\Pages;

use App\Filament\App\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * business_entity_id is guarded and assigned explicitly from the active
     * tenant, never from the form payload.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $client = new Client;
        $client->fill($data);
        $client->forceFill(['business_entity_id' => Filament::getTenant()->getKey()]);
        $client->save();

        return $client;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
