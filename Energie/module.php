<?php

declare(strict_types=1);

class Energie extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterTimer('DeployScriptsTimer', 0, 'SDP_DeployScripts($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Verzeichnis vom Modul ermitteln
        $moduleDir = IPS_GetKernelDir() . 'modules' . DIRECTORY_SEPARATOR . 'ImbaMDT.Energie' . DIRECTORY_SEPARATOR . 'scripts';
    
        // Nur wenn Verzeichnis existiert
        if (is_dir($moduleDir)) {
            $files = scandir($moduleDir);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $ident = pathinfo($file, PATHINFO_FILENAME);
                    $existingID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                    if ($existingID === false) {
                        $scriptID = IPS_CreateScript(0);
                        IPS_SetName($scriptID, $ident);
                        IPS_SetIdent($scriptID, $ident);
                        IPS_SetParent($scriptID, $this->InstanceID);
    
                        // Inhalt aus Datei laden und setzen
                        $code = file_get_contents($moduleDir . DIRECTORY_SEPARATOR . $file);
                        IPS_SetScriptContent($scriptID, $code);
                    }
                }
            }
        }
    }

    public function DeployScripts()
    {
        $scriptsDir = __DIR__ . '/scripts';
        if (!is_dir($scriptsDir)) {
            $this->SendDebug('Energie', 'Scripts-Verzeichnis nicht gefunden', 0);
            return;
        }

        foreach (scandir($scriptsDir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $filePath = $scriptsDir . '/' . $file;
            $scriptContent = file_get_contents($filePath);

            $scriptName = 'SDP_' . pathinfo($file, PATHINFO_FILENAME);
            $existingID = @IPS_GetObjectIDByName($scriptName, 0);

            if ($existingID === false) {
                $id = IPS_CreateScript(0);
                IPS_SetName($id, $scriptName);
                IPS_SetParent($id, 0);
            } else {
                $id = $existingID;
            }

            IPS_SetScriptContent($id, $scriptContent);
            $this->SendDebug('Energie', "Skript $scriptName deployed (ID: $id)", 0);
        }
    }

}
