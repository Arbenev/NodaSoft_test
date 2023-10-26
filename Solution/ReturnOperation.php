<?php

namespace NW\WebService\References\Operations\Notification;

class ReturnOperation extends ReferencesOperation
{

    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    private $data;
    private $client;
    private $creator;
    private $expert;

    /**
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $this->data = (array) $this->getRequest('data');
        $resellerId = $this->data['resellerId'];
        $notificationType = (int) $this->data['notificationType'];

        $result = $this->initResult($this->data);
        $this->checkReseller($resellerId);
        $this->client = Contractor::getById((int) $this->data['clientId']);
        $cFullName = $this->checkContractor($resellerId);
        $this->creator = $this->checkEmployee($this->data['creatorId'], 'Creator');
        $this->expert = $this->checkEmployee($this->data['expertId'], 'Expert');
        $differences = $this->makeDifferences($this->data['differences'], $notificationType, $resellerId);

        $templateData = $this->createTemplateData($cFullName, $differences);
        $this->checkTemplateData($templateData);

        $emailFrom = getResellerEmailFrom($resellerId);
        $this->sentEmployeeNotice($emailFrom, $resellerId, $templateData, $result);

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($notificationType === self::TYPE_CHANGE && !empty($this->data['differences']['to'])) {
            $this->sendClientNotice($this->data['differences']['to'], $emailFrom, $templateData, $resellerId);
        }

        return $result;
    }

    private function createTemplateData($cFullName, $differences)
    {
        $templateData = [
            'COMPLAINT_ID' => (int) $this->data['complaintId'],
            'COMPLAINT_NUMBER' => (string) $this->data['complaintNumber'],
            'CREATOR_ID' => (int) $this->data['creatorId'],
            'CREATOR_NAME' => $this->creator->getFullName(),
            'EXPERT_ID' => (int) $this->data['expertId'],
            'EXPERT_NAME' => $this->expert->getFullName(),
            'CLIENT_ID' => (int) $this->data['clientId'],
            'CLIENT_NAME' => $cFullName,
            'CONSUMPTION_ID' => (int) $this->data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string) $this->data['consumptionNumber'],
            'AGREEMENT_NUMBER' => (string) $this->data['agreementNumber'],
            'DATE' => (string) $this->data['date'],
            'DIFFERENCES' => $differences,
        ];
        return $templateData;
    }

    private function initResult(): array
    {
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];

        return $result;
    }

    private function checkReseller($resellerId): void
    {
        $reseller = Seller::getById((int) $resellerId);
        if ($reseller === null) {
            throw new \Exception('Seller not found!', 400);
        }
    }

    private function checkContractor($resellerId): string
    {
        if ($this->client === null || $this->client->type !== Contractor::TYPE_CUSTOMER || $this->client->Seller->id !== $resellerId) {
            throw new \Exception('сlient not found!', 400);
        }

        $cFullName = $this->client->getFullName();
        if (empty($this->client->getFullName())) {
            $cFullName = $this->client->name;
        }

        return $cFullName;
    }

    private function checkEmployee($id, $type): Contractor
    {
        $contractor = Employee::getById((int) $id);
        if ($contractor === null) {
            throw new \Exception($type . ' not found!', 400);
        }
        return $contractor;
    }

    private function makeDifferences($dataDiffferences, $notificationType, $resellerId): string
    {
        $differences = '';
        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($dataDiffferences)) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int) $dataDiffferences['from']),
                'TO' => Status::getName((int) $dataDiffferences['to']),
                    ], $resellerId);
        }
        return $differences;
    }

    private function checkTemplateData($templateData): void
    {
        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    private function sentEmployeeNotice($emailFrom, $resellerId, $templateData, $result)
    {
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [// MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo' => $email,
                        'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                        ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }
    }

    private function sendClientNotice($differences, $emailFrom, $templateData, $resellerId)
    {
        if (!empty($emailFrom) && !empty($this->client->email)) {
            MessagesClient::sendMessage([
                0 => [// MessageTypes::EMAIL
                    'emailFrom' => $emailFrom,
                    'emailTo' => $this->client->email,
                    'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                    'message' => __('complaintClientEmailBody', $templateData, $resellerId),
                ],
                    ], $resellerId, $this->client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int) $differences);
            $result['notificationClientByEmail'] = true;
        }

        if (!empty($this->client->mobile)) {
            $res = NotificationManager::send($resellerId, $this->client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int) $differences, $templateData, $error);
            if ($res) {
                $result['notificationClientBySms']['isSent'] = true;
            }
            if (!empty($error)) {
                $result['notificationClientBySms']['message'] = $error;
            }
        }
    }
}
