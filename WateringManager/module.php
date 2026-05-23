<?php

declare(strict_types=1);

class WateringManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('DefaultDuration', 10);
        $this->RegisterPropertyInteger('PauseBetweenValves', 10);
        $this->RegisterPropertyInteger('MaxDuration', 60);

        $this->RegisterPropertyString('Valves', '[]');
        $this->RegisterPropertyString('Groups', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $file = __DIR__ . '/form.json';

        if (!file_exists($file)) {
            return json_encode([
                'elements' => [
                    [
                        'type' => 'Label',
                        'caption' => 'form.json wurde nicht gefunden: ' . $file
                    ]
                ],
                'actions' => []
            ]);
        }

        return file_get_contents($file);
    }

    public function EmergencyStop()
    {
        $this->SendDebug('EmergencyStop', 'Alle Ventile ausschalten wurde ausgelöst.', 0);
    }
}