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

        $this->RegisterAttributeString('CurrentMode', '');
        $this->RegisterAttributeString('CurrentGroup', '');
        $this->RegisterAttributeInteger('CurrentIndex', -1);
        $this->RegisterAttributeInteger('EndTime', 0);
        $this->RegisterAttributeString('ActiveValveName', '');

        $this->RegisterTimer('RunTimer', 0, 'BWM_RunNextStep($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        $this->RegisterVariableString('Status', 'Status', '', 10);
        $this->RegisterVariableString('ActiveValve', 'Aktuelles Ventil', '', 20);

        $this->RegisterVariableInteger('Duration', 'Laufzeit Minuten', '', 30);
        $this->EnableAction('Duration');

        $this->RegisterVariableInteger('SelectedValve', 'Ventil auswählen', 'BWM.Valves.' . $this->InstanceID, 40);
        $this->EnableAction('SelectedValve');

        $this->RegisterVariableBoolean('StartValve', 'Ventil starten', '~Switch', 50);
        $this->EnableAction('StartValve');

        $this->RegisterVariableInteger('SelectedGroup', 'Gruppe auswählen', 'BWM.Groups.' . $this->InstanceID, 60);
        $this->EnableAction('SelectedGroup');

        $this->RegisterVariableBoolean('StartGroup', 'Gruppe starten', '~Switch', 70);
        $this->EnableAction('StartGroup');

        $this->RegisterVariableBoolean('Stop', 'Stop / Not-Aus', '~Switch', 80);
        $this->EnableAction('Stop');

        if (GetValue($this->GetIDForIdent('Duration')) <= 0) {
            SetValue($this->GetIDForIdent('Duration'), $this->ReadPropertyInteger('DefaultDuration'));
        }

        SetValue($this->GetIDForIdent('Status'), 'Bereit');
        SetValue($this->GetIDForIdent('ActiveValve'), '-');
        SetValue($this->GetIDForIdent('StartValve'), false);
        SetValue($this->GetIDForIdent('StartGroup'), false);
        SetValue($this->GetIDForIdent('Stop'), false);
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

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Duration':
                SetValue($this->GetIDForIdent($Ident), (int)$Value);
                break;

            case 'SelectedValve':
                SetValue($this->GetIDForIdent($Ident), (int)$Value);
                break;

            case 'SelectedGroup':
                SetValue($this->GetIDForIdent($Ident), (int)$Value);
                break;

            case 'StartValve':
                SetValue($this->GetIDForIdent('StartValve'), false);
                $this->StartSelectedValve();
                break;

            case 'StartGroup':
                SetValue($this->GetIDForIdent('StartGroup'), false);
                $this->StartSelectedGroup();
                break;

            case 'Stop':
                SetValue($this->GetIDForIdent('Stop'), false);
                $this->EmergencyStop();
                break;

            default:
                throw new Exception('Ungültige Aktion: ' . $Ident);
        }
    }

    public function StartSelectedValve()
    {
        $valves = $this->GetActiveValves();

        if (count($valves) === 0) {
            $this->SetStatusText('Keine aktiven Ventile konfiguriert');
            return;
        }

        $index = GetValue($this->GetIDForIdent('SelectedValve'));

        if (!isset($valves[$index])) {
            $this->SetStatusText('Ungültiges Ventil ausgewählt');
            return;
        }

        $duration = $this->GetRequestedDurationSeconds($valves[$index]);

        $this->AllValvesOff();
        $this->SwitchValve($valves[$index], true);

        $this->WriteAttributeString('CurrentMode', 'valve');
        $this->WriteAttributeString('CurrentGroup', '');
        $this->WriteAttributeInteger('CurrentIndex', $index);
        $this->WriteAttributeInteger('EndTime', time() + $duration);
        $this->WriteAttributeString('ActiveValveName', (string)$valves[$index]['Name']);

        SetValue($this->GetIDForIdent('ActiveValve'), (string)$valves[$index]['Name']);
        $this->SetStatusText('Bewässerung läuft: ' . $valves[$index]['Name']);

        $this->SetTimerInterval('RunTimer', $duration * 1000);
    }

    public function StartSelectedGroup()
    {
        $groups = $this->GetActiveGroups();

        if (count($groups) === 0) {
            $this->SetStatusText('Keine aktiven Gruppen konfiguriert');
            return;
        }

        $groupIndex = GetValue($this->GetIDForIdent('SelectedGroup'));

        if (!isset($groups[$groupIndex])) {
            $this->SetStatusText('Ungültige Gruppe ausgewählt');
            return;
        }

        $this->AllValvesOff();

        $this->WriteAttributeString('CurrentMode', 'group');
        $this->WriteAttributeString('CurrentGroup', (string)$groups[$groupIndex]['Name']);
        $this->WriteAttributeInteger('CurrentIndex', -1);
        $this->WriteAttributeInteger('EndTime', 0);
        $this->WriteAttributeString('ActiveValveName', '');

        $this->RunNextStep();
    }

    public function RunNextStep()
    {
        $this->SetTimerInterval('RunTimer', 0);

        $mode = $this->ReadAttributeString('CurrentMode');

        if ($mode === '') {
            return;
        }

        $this->AllValvesOff();

        if ($mode === 'valve') {
            $this->WriteAttributeString('CurrentMode', '');
            $this->WriteAttributeString('CurrentGroup', '');
            $this->WriteAttributeInteger('CurrentIndex', -1);
            $this->WriteAttributeInteger('EndTime', 0);
            $this->WriteAttributeString('ActiveValveName', '');

            SetValue($this->GetIDForIdent('ActiveValve'), '-');
            $this->SetStatusText('Bereit');
            return;
        }

        if ($mode !== 'group') {
            $this->EmergencyStop();
            return;
        }

        $groups = $this->GetActiveGroups();
        $valves = $this->GetActiveValves();

        $groupName = $this->ReadAttributeString('CurrentGroup');
        $group = null;

        foreach ($groups as $item) {
            if ((string)$item['Name'] === $groupName) {
                $group = $item;
                break;
            }
        }

        if ($group === null) {
            $this->EmergencyStop();
            $this->SetStatusText('Gruppe nicht gefunden');
            return;
        }

        $valveNames = array_filter(array_map('trim', explode(',', (string)$group['Valves'])));

        $currentIndex = $this->ReadAttributeInteger('CurrentIndex');
        $nextIndex = $currentIndex + 1;

        if (!isset($valveNames[$nextIndex])) {
            $this->WriteAttributeString('CurrentMode', '');
            $this->WriteAttributeString('CurrentGroup', '');
            $this->WriteAttributeInteger('CurrentIndex', -1);
            $this->WriteAttributeInteger('EndTime', 0);
            $this->WriteAttributeString('ActiveValveName', '');

            SetValue($this->GetIDForIdent('ActiveValve'), '-');
            $this->SetStatusText('Gruppe beendet: ' . $groupName);
            return;
        }

        $nextValveName = $valveNames[$nextIndex];
        $nextValve = null;

        foreach ($valves as $valve) {
            if ((string)$valve['Name'] === $nextValveName) {
                $nextValve = $valve;
                break;
            }
        }

        if ($nextValve === null) {
            $this->EmergencyStop();
            $this->SetStatusText('Ventil nicht gefunden: ' . $nextValveName);
            return;
        }

        $durationMinutes = (int)($group['Duration'] ?? 0);

        if ($durationMinutes <= 0) {
            $durationSeconds = $this->GetRequestedDurationSeconds($nextValve);
        } else {
            $durationSeconds = $this->LimitDurationSeconds($durationMinutes * 60);
        }

        $this->SwitchValve($nextValve, true);

        $this->WriteAttributeInteger('CurrentIndex', $nextIndex);
        $this->WriteAttributeInteger('EndTime', time() + $durationSeconds);
        $this->WriteAttributeString('ActiveValveName', (string)$nextValve['Name']);

        SetValue($this->GetIDForIdent('ActiveValve'), (string)$nextValve['Name']);
        $this->SetStatusText('Gruppe läuft: ' . $groupName . ' / Ventil: ' . $nextValve['Name']);

        $pause = $this->ReadPropertyInteger('PauseBetweenValves');
        $this->SetTimerInterval('RunTimer', ($durationSeconds + $pause) * 1000);
    }

    public function EmergencyStop()
    {
        $this->SetTimerInterval('RunTimer', 0);

        $this->AllValvesOff();

        $this->WriteAttributeString('CurrentMode', '');
        $this->WriteAttributeString('CurrentGroup', '');
        $this->WriteAttributeInteger('CurrentIndex', -1);
        $this->WriteAttributeInteger('EndTime', 0);
        $this->WriteAttributeString('ActiveValveName', '');

        SetValue($this->GetIDForIdent('ActiveValve'), '-');
        SetValue($this->GetIDForIdent('StartValve'), false);
        SetValue($this->GetIDForIdent('StartGroup'), false);
        SetValue($this->GetIDForIdent('Stop'), false);

        $this->SetStatusText('Gestoppt');
    }

    private function RegisterProfiles()
    {
        $this->RegisterValveProfile();
        $this->RegisterGroupProfile();
    }

    private function RegisterValveProfile()
    {
        $profile = 'BWM.Valves.' . $this->InstanceID;

        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }

        foreach (IPS_GetVariableProfile($profile)['Associations'] as $association) {
            IPS_SetVariableProfileAssociation($profile, $association['Value'], '', '', -1);
        }

        $valves = $this->GetActiveValves();

        foreach ($valves as $index => $valve) {
            IPS_SetVariableProfileAssociation($profile, $index, (string)$valve['Name'], '', -1);
        }
    }

    private function RegisterGroupProfile()
    {
        $profile = 'BWM.Groups.' . $this->InstanceID;

        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }

        foreach (IPS_GetVariableProfile($profile)['Associations'] as $association) {
            IPS_SetVariableProfileAssociation($profile, $association['Value'], '', '', -1);
        }

        $groups = $this->GetActiveGroups();

        foreach ($groups as $index => $group) {
            IPS_SetVariableProfileAssociation($profile, $index, (string)$group['Name'], '', -1);
        }
    }

    private function GetActiveValves(): array
    {
        $valves = json_decode($this->ReadPropertyString('Valves'), true);

        if (!is_array($valves)) {
            return [];
        }

        return array_values(array_filter($valves, function ($valve) {
            return isset($valve['Active'])
                && (bool)$valve['Active'] === true
                && trim((string)($valve['Name'] ?? '')) !== ''
                && (int)($valve['VariableID'] ?? 0) > 0;
        }));
    }

    private function GetActiveGroups(): array
    {
        $groups = json_decode($this->ReadPropertyString('Groups'), true);

        if (!is_array($groups)) {
            return [];
        }

        return array_values(array_filter($groups, function ($group) {
            return isset($group['Active'])
                && (bool)$group['Active'] === true
                && trim((string)($group['Name'] ?? '')) !== '';
        }));
    }

    private function GetRequestedDurationSeconds(array $valve): int
    {
        $durationMinutes = GetValue($this->GetIDForIdent('Duration'));

        if ($durationMinutes <= 0) {
            $durationMinutes = (int)($valve['Duration'] ?? 0);
        }

        if ($durationMinutes <= 0) {
            $durationMinutes = $this->ReadPropertyInteger('DefaultDuration');
        }

        return $this->LimitDurationSeconds($durationMinutes * 60);
    }

    private function LimitDurationSeconds(int $seconds): int
    {
        $maxSeconds = $this->ReadPropertyInteger('MaxDuration') * 60;

        if ($seconds <= 0) {
            $seconds = $this->ReadPropertyInteger('DefaultDuration') * 60;
        }

        if ($seconds > $maxSeconds) {
            $seconds = $maxSeconds;
        }

        return $seconds;
    }

    private function SwitchValve(array $valve, bool $state): void
    {
        $variableID = (int)($valve['VariableID'] ?? 0);

        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            throw new Exception('Ungültige VariableID für Ventil: ' . ($valve['Name'] ?? 'Unbekannt'));
        }

        $value = $this->GetValveSwitchValue($valve, $state);
        $variable = IPS_GetVariable($variableID);

        if ((int)$variable['VariableAction'] > 0) {
            RequestAction($variableID, $value);
        } else {
            SetValue($variableID, $value);
        }

        $this->SendDebug(
            'SwitchValve',
            ($state ? 'Ein: ' : 'Aus: ') . ($valve['Name'] ?? 'Unbekannt') . ' / Wert: ' . json_encode($value),
            0
        );
    }

    private function AllValvesOff(): void
    {
        foreach ($this->GetActiveValves() as $valve) {
            try {
                $this->SwitchValve($valve, false);
            } catch (Throwable $e) {
                $this->SendDebug('AllValvesOff', $e->getMessage(), 0);
            }
        }
    }

    private function GetValveSwitchValue(array $valve, bool $state)
    {
        $autoValues = (bool)($valve['AutoValues'] ?? true);

        if (!$autoValues) {
            $manualValue = $state ? ($valve['OnValue'] ?? '') : ($valve['OffValue'] ?? '');
            return $this->ConvertValue($manualValue);
        }

        $variableID = (int)($valve['VariableID'] ?? 0);
        $variable = IPS_GetVariable($variableID);
        $variableType = (int)$variable['VariableType'];

        if ($variableType === 0) {
            return $state;
        }

        $profileName = $variable['VariableCustomProfile'] ?: $variable['VariableProfile'];

        if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
            $detectedValue = $this->DetectProfileSwitchValue($profileName, $state);

            if ($detectedValue !== null) {
                return $this->ConvertValue($detectedValue);
            }
        }

        $manualValue = $state ? ($valve['OnValue'] ?? '') : ($valve['OffValue'] ?? '');

        if ((string)$manualValue !== '') {
            return $this->ConvertValue($manualValue);
        }

        if ($variableType === 1 || $variableType === 2) {
            return $state ? 1 : 0;
        }

        if ($variableType === 3) {
            return $state ? 'ON' : 'OFF';
        }

        throw new Exception('Schaltwert konnte nicht ermittelt werden für Ventil: ' . ($valve['Name'] ?? 'Unbekannt'));
    }

    private function DetectProfileSwitchValue(string $profileName, bool $state)
    {
        $profile = IPS_GetVariableProfile($profileName);

        if (!isset($profile['Associations']) || !is_array($profile['Associations'])) {
            return null;
        }

        $onNames = [
            'ein',
            'on',
            'open',
            'öffnen',
            'geöffnet',
            'start',
            'true',
            'an',
            'aktiv'
        ];

        $offNames = [
            'aus',
            'off',
            'close',
            'geschlossen',
            'schließen',
            'stop',
            'false',
            'inaktiv'
        ];

        $searchNames = $state ? $onNames : $offNames;

        foreach ($profile['Associations'] as $association) {
            $name = strtolower((string)($association['Name'] ?? ''));

            foreach ($searchNames as $searchName) {
                if ($name === $searchName || str_contains($name, $searchName)) {
                    return $association['Value'];
                }
            }
        }

        return null;
    }

    private function ConvertValue($value)
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        $value = trim((string)$value);
        $lower = strtolower($value);

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        return $value;
    }

    private function SetStatusText(string $text): void
    {
        try {
            SetValue($this->GetIDForIdent('Status'), $text);
        } catch (Throwable $e) {
            // Statusvariable existiert eventuell noch nicht.
        }

        $this->SendDebug('Status', $text, 0);
    }
}