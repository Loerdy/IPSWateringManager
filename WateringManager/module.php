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
        IPS_SetVariableCustomProfile($this->GetIDForIdent('SelectedValve'), 'BWM.Valves.' . $this->InstanceID);

        $this->RegisterVariableBoolean('StartValve', 'Ventil starten', '~Switch', 50);
        $this->EnableAction('StartValve');

        $this->RegisterVariableInteger('SelectedGroup', 'Gruppe auswählen', 'BWM.Groups.' . $this->InstanceID, 60);
        $this->EnableAction('SelectedGroup');
        IPS_SetVariableCustomProfile($this->GetIDForIdent('SelectedGroup'), 'BWM.Groups.' . $this->InstanceID);

        $this->RegisterVariableBoolean('StartGroup', 'Gruppe starten', '~Switch', 70);
        $this->EnableAction('StartGroup');

        $this->RegisterVariableBoolean('Stop', 'Stop / Not-Aus', '~Switch', 80);
        $this->EnableAction('Stop');

        if ($this->HasIdentSafe('Duration') && GetValue($this->GetIDForIdent('Duration')) <= 0) {
            SetValue($this->GetIDForIdent('Duration'), $this->ReadPropertyInteger('DefaultDuration'));
        }

        $this->NormalizeSelectionValues();

        $this->SafeSetValue('Status', 'Bereit');
        $this->SafeSetValue('ActiveValve', '-');
        $this->SafeSetValue('StartValve', false);
        $this->SafeSetValue('StartGroup', false);
        $this->SafeSetValue('Stop', false);
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

        $form = json_decode(file_get_contents($file), true);

        if (!is_array($form)) {
            return json_encode([
                'elements' => [
                    [
                        'type' => 'Label',
                        'caption' => 'form.json ist kein gültiges JSON.'
                    ]
                ],
                'actions' => []
            ]);
        }

        $valveCount = count($this->GetActiveValves());
        $switchableValveCount = 0;

        foreach ($this->GetActiveValves() as $valve) {
            if ((int)($valve['VariableID'] ?? 0) > 0) {
                $switchableValveCount++;
            }
        }

        array_unshift($form['elements'], [
            'type' => 'Label',
            'caption' => 'Diagnose: Aktive Ventile: ' . $valveCount . ' / mit VariableID: ' . $switchableValveCount . '. Nach Änderungen bitte Übernehmen drücken und die Instanz neu öffnen.'
        ]);

        $options = $this->BuildValveOptions();

        if (isset($form['elements']) && is_array($form['elements'])) {
            foreach ($form['elements'] as &$element) {
                if (($element['type'] ?? '') !== 'ExpansionPanel' || ($element['caption'] ?? '') !== 'Gruppen') {
                    continue;
                }

                if (!isset($element['items']) || !is_array($element['items'])) {
                    continue;
                }

                foreach ($element['items'] as &$item) {
                    if (($item['type'] ?? '') !== 'List' || ($item['name'] ?? '') !== 'Groups') {
                        continue;
                    }

                    if (isset($item['columns']) && is_array($item['columns'])) {
                        foreach ($item['columns'] as &$column) {
                            if (isset($column['name']) && preg_match('/^Valve[1-8]$/', (string)$column['name'])) {
                                $column['edit'] = [
                                    'type' => 'Select',
                                    'options' => $options
                                ];
                            }
                        }
                    }

                    if (isset($item['form']) && is_array($item['form'])) {
                        foreach ($item['form'] as &$field) {
                            if (isset($field['name']) && preg_match('/^Valve[1-8]$/', (string)$field['name'])) {
                                $field['options'] = $options;
                            }
                        }
                    }
                }
            }
        }

        return json_encode($form);
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
                if ((bool)$Value === true) {
                    $this->StartSelectedValve();
                }
                break;

            case 'StartGroup':
                SetValue($this->GetIDForIdent('StartGroup'), false);
                if ((bool)$Value === true) {
                    $this->StartSelectedGroup();
                }
                break;

            case 'Stop':
                SetValue($this->GetIDForIdent('Stop'), false);
                if ((bool)$Value === true) {
                    $this->EmergencyStop();
                }
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

        try {
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
        } catch (Throwable $e) {
            $this->EmergencyStop();
            $this->SetStatusText('Fehler beim Starten des Ventils: ' . $e->getMessage());
        }
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

        $valveNames = $this->GetGroupValveNames($groups[$groupIndex]);

        if (count($valveNames) === 0) {
            $this->SetStatusText('Gruppe enthält keine Ventile: ' . (string)$groups[$groupIndex]['Name']);
            return;
        }

        try {
            $this->AllValvesOff();

            $this->WriteAttributeString('CurrentMode', 'group');
            $this->WriteAttributeString('CurrentGroup', (string)$groups[$groupIndex]['Name']);
            $this->WriteAttributeInteger('CurrentIndex', -1);
            $this->WriteAttributeInteger('EndTime', 0);
            $this->WriteAttributeString('ActiveValveName', '');

            $this->RunNextStep();
        } catch (Throwable $e) {
            $this->EmergencyStop();
            $this->SetStatusText('Fehler beim Starten der Gruppe: ' . $e->getMessage());
        }
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

            $this->SafeSetValue('ActiveValve', '-');
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

        $valveNames = $this->GetGroupValveNames($group);

        $currentIndex = $this->ReadAttributeInteger('CurrentIndex');
        $nextIndex = $currentIndex + 1;

        if (!isset($valveNames[$nextIndex])) {
            $this->WriteAttributeString('CurrentMode', '');
            $this->WriteAttributeString('CurrentGroup', '');
            $this->WriteAttributeInteger('CurrentIndex', -1);
            $this->WriteAttributeInteger('EndTime', 0);
            $this->WriteAttributeString('ActiveValveName', '');

            $this->SafeSetValue('ActiveValve', '-');
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

        try {
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
        } catch (Throwable $e) {
            $this->EmergencyStop();
            $this->SetStatusText('Fehler im Gruppenlauf: ' . $e->getMessage());
        }
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

        $this->SafeSetValue('ActiveValve', '-');
        $this->SafeSetValue('StartValve', false);
        $this->SafeSetValue('StartGroup', false);
        $this->SafeSetValue('Stop', false);

        $this->SetStatusText('Gestoppt');
    }

    private function RegisterProfiles(): void
    {
        $this->RegisterValveProfile();
        $this->RegisterGroupProfile();
    }

    private function RegisterValveProfile(): void
    {
        $profile = 'BWM.Valves.' . $this->InstanceID;
        $valves = $this->GetActiveValves();

        $associations = [];

        foreach ($valves as $index => $valve) {
            $associations[$index] = (string)$valve['Name'];
        }

        $this->RebuildIntegerAssociationProfile($profile, $associations);
    }

    private function RegisterGroupProfile(): void
    {
        $profile = 'BWM.Groups.' . $this->InstanceID;
        $groups = $this->GetActiveGroups();

        $associations = [];

        foreach ($groups as $index => $group) {
            $associations[$index] = (string)$group['Name'];
        }

        $this->RebuildIntegerAssociationProfile($profile, $associations);
    }

    private function RebuildIntegerAssociationProfile(string $profile, array $associations): void
    {
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }

        $oldProfile = IPS_GetVariableProfile($profile);

        if (isset($oldProfile['Associations']) && is_array($oldProfile['Associations'])) {
            foreach ($oldProfile['Associations'] as $association) {
                IPS_SetVariableProfileAssociation($profile, $association['Value'], '', '', -1);
            }
        }

        $max = count($associations) > 0 ? max(array_keys($associations)) : 0;

        IPS_SetVariableProfileValues($profile, 0, $max, 1);
        IPS_SetVariableProfileDigits($profile, 0);

        foreach ($associations as $value => $caption) {
            IPS_SetVariableProfileAssociation($profile, (int)$value, $caption, '', -1);
        }
    }

    private function NormalizeSelectionValues(): void
    {
        $valves = $this->GetActiveValves();

        if ($this->HasIdentSafe('SelectedValve')) {
            $selectedValve = GetValue($this->GetIDForIdent('SelectedValve'));
            if (!isset($valves[$selectedValve])) {
                SetValue($this->GetIDForIdent('SelectedValve'), 0);
            }
        }

        $groups = $this->GetActiveGroups();

        if ($this->HasIdentSafe('SelectedGroup')) {
            $selectedGroup = GetValue($this->GetIDForIdent('SelectedGroup'));
            if (!isset($groups[$selectedGroup])) {
                SetValue($this->GetIDForIdent('SelectedGroup'), 0);
            }
        }
    }

    private function BuildValveOptions(): array
    {
        $options = [
            [
                'caption' => '-',
                'value' => ''
            ]
        ];

        foreach ($this->GetActiveValves() as $valve) {
            $name = trim((string)($valve['Name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $options[] = [
                'caption' => $name,
                'value' => $name
            ];
        }

        return $options;
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
                && trim((string)($valve['Name'] ?? '')) !== '';
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

    private function GetGroupValveNames(array $group): array
    {
        $names = [];

        for ($i = 1; $i <= 8; $i++) {
            $key = 'Valve' . $i;
            $name = trim((string)($group[$key] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        // Abwärtskompatibilität zur alten Konfiguration mit kommaseparierter Liste.
        if (count($names) === 0 && isset($group['Valves'])) {
            $oldNames = array_filter(array_map('trim', explode(',', (string)$group['Valves'])));
            foreach ($oldNames as $oldName) {
                if ($oldName !== '') {
                    $names[] = $oldName;
                }
            }
        }

        return $names;
    }

    private function GetRequestedDurationSeconds(array $valve): int
    {
        $durationMinutes = 0;

        if ($this->HasIdentSafe('Duration')) {
            $durationMinutes = (int)GetValue($this->GetIDForIdent('Duration'));
        }

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

    private function HasIdentSafe(string $ident): bool
    {
        try {
            $this->GetIDForIdent($ident);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function SafeSetValue(string $ident, $value): void
    {
        try {
            $id = $this->GetIDForIdent($ident);
            SetValue($id, $value);
        } catch (Throwable $e) {
            // Variable existiert eventuell noch nicht.
        }
    }

    private function SetStatusText(string $text): void
    {
        $this->SafeSetValue('Status', $text);
        $this->SendDebug('Status', $text, 0);
    }
}
