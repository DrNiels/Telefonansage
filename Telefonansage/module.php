<?php

declare(strict_types=1);
class Telefonansage extends IPSModule
{

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('VoIPInstanceID', 0);
        $this->RegisterPropertyInteger('TTSInstanceID', 0);
        $this->RegisterPropertyInteger('WaitForConnection', 20);

        $this->RegisterVariableString('PhoneNumber', $this->Translate('Phone Number'));
        $this->RegisterVariableString('Text', $this->Translate('Text'));
        $this->RegisterVariableString('DTMF', $this->Translate('DTMF Sound'));

        $this->EnableAction('PhoneNumber');
        $this->EnableAction('Text');

        $this->RegisterScript('CallScript', $this->Translate('Start Call'), '<?php TA_StartCall(IPS_GetParent($_IPS["SELF"]));');

        $this->RegisterTimer('CloseConnectionTimer', 0, 'TA_CloseConnection($_IPS["TARGET"]);');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $this->RegisterMessage($this->ReadPropertyInteger('VoIPInstanceID'), 21000); /* VOIP_EVENT */
    }

    public function RequestAction($ident, $value)
    {
        $this->SetValue($ident, $value);

        switch ($ident) {
            case 'Text':
                $id = json_decode($this->GetBuffer('CallID'));
                if ($id === null) {
                    break;
                }
                $c = VoIP_GetConnection($this->ReadPropertyInteger('VoIPInstanceID'), $id);
                if ($c['Connected']) {
                    // VoIP_Playwave() unterstützt ausschließlich WAV im Format: 16 Bit, 8000 Hz, Mono.
                    VoIP_PlayWave($this->ReadPropertyInteger('VoIPInstanceID'), $id, TTSAWSPOLLY_GenerateFile($this->ReadPropertyInteger('TTSInstanceID'), $value));
                }
                break;
        }
    }

    public function MessageSink($timestamp, $senderID, $messageID, $data) {
        $this->SendDebug('Message Received', json_encode([$senderID, $messageID, $data]), 0);
        // We are only registered to VOIP_EVENT of the defined VoIP instance, so no need to validate $senderID and $messageID
        // $data = [ connectionID, event, data ]
        if ($data[0] === json_decode($this->GetBuffer('CallID'))) {
            switch ($data[1]) {
                case 'Connect':
                    // Disable close timer and play text
                    $this->SetTimerInterval('CloseConnectionTimer', 0);
                    // VoIP_Playwave() unterstützt ausschließlich WAV im Format: 16 Bit, 8000 Hz, Mono.
                    VoIP_PlayWave($this->ReadPropertyInteger('VoIPInstanceID'), $data[0], TTSAWSPOLLY_GenerateFile($this->ReadPropertyInteger('TTSInstanceID'), $this->GetBuffer('Text')));
                    break;

                case 'Disconnect':
                    $this->SetBuffer('CallID', '');
                    break;

                case 'DTMF':
                    $this->SetValue('DTMF', $data[2]);
                    break;
            }
        }
    }

    public function StartCall()
    {
        $this->StartCallEx($this->GetValue('PhoneNumber'), $this->GetValue('Text'));
    }

    public function StartCallEx(string $PhoneNumber, string $Text)
    {
        if (json_decode($this->GetBuffer('ConnectionID')) != '') {
            echo $this->Translate('The instance is already calling');
            return;
        }
        $id = VoIP_Connect($this->ReadPropertyInteger('VoIPInstanceID'), $PhoneNumber);

        $this->SetBuffer('CallID', json_encode($id));
        $this->SetBuffer('Text', $Text);
        $this->SetTimerInterval('CloseConnectionTimer', 1000 * $this->ReadPropertyInteger('WaitForConnection'));
    }

    public function CloseConnection() {
        $id = json_decode($this->GetBuffer('CallID'));
        if ($id !== null) {
            VOIP_Disconnect($this->ReadPropertyInteger('VoIPInstanceID'), $id);
        }
    }
}