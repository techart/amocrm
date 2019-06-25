<?php

namespace Techart\AmoCRM;

use AmoCRM\Models\Contact;

/**
 * Class AmoFormIntegrator
 *
 * Класс для отправки лидов и контактов в AmoCRM. Использует пакет dotzero/amocrm для связи с AmoCRM.
 *
 * @package App
 */
class FormIntegrator
{
	protected $client;
	protected $fields = [];
	protected $fieldsIds;

	/**
	 * @param string $subdomain
	 * @param string $login
	 * @param string $key
	 */
	public function __construct($subdomain, $login, $key)
	{
		$this->client = new \AmoCRM\Client($subdomain, $login, $key);
	}

	/**
	 * Метод проверяет наличие контакта с такими данными. Если его нет, то создает. Потом создает сделку с привязанным
	 * контактом.
	 *
	 * @param string $leadName Название лида
	 * @param string $email Email контакта
	 * @param string $phone Телефон контакта
	 * @param string $contactName Имя контакта
	 * @param array $leadFields Основные поля лида со значениями
	 * @param array $leadCustomFields Пользовательские поля лида со значениями
	 * @param array $contactCustomFields Пользовательские поля контакта со значениями
	 * @return int|bool $leadId ID созданного лида
	 */
	public function sendLead($leadName, $email = '', $phone = '', $contactName = '', $leadFields = [], $leadCustomFields = [], $contactCustomFields = [])
	{
		$contact = $this->getOrCreateContact($email, $phone, $contactName, $contactCustomFields);
		return $this->createLead($leadName, $contact['id'], $leadFields, $leadCustomFields);
	}

	/**
	 * Метод получает контакт с переданными данными. При его отсутсвии в системе - добавляет. Возвращает массив данных
	 * контакта.
	 *
	 * @param string $email
	 * @param string $phone
	 * @param string $name
	 * @return array
	 */
	public function getOrCreateContact($email, $phone = '', $name = '', $contactCustomFields = [])
	{
		$contactData = $this->findContact(array_filter([$email, $phone]));
		if (is_null($contactData)) {
			$contactId = $this->createContact($email, $phone, $name, $contactCustomFields);
			$contactData = $this->findContactById($contactId);
		} else {
			$this->refreshContact($contactData, $email, $phone, $contactCustomFields);
		}
		return $contactData;
	}

	/**
	 * Соаздает сделку с переданными данными. Возвращает id созданной сделки.
	 * @param string $name
	 * @param int $contactId
	 * @param array $fields
	 * @param array $customFields
	 * @return int $leadId
	 */
	public function createLead($name, $contactId, $fields = [], $customFields = [])
	{
		$lead = $this->client->lead;
		$lead['name'] = $name;
		foreach ($fields as $fieldName => $fieldValue) {
			$lead[$fieldName] = $fieldValue;
		}
		foreach ($customFields as $fieldId => $fieldValue) {
			$lead->addCustomField($fieldId, $fieldValue);
		}
		$leadId = $lead->apiAdd();

		if ($contactId) {
			$link = $this->client->links;
			$link['from'] = 'leads';
			$link['from_id'] = $leadId;
			$link['to'] = 'contacts';
			$link['to_id'] = $contactId;
			$link->apiLink();
		}

		return $leadId;
	}

	/**
	 * Метод ищет контакт по переданным данным (в обращении к AmoCRM для поиска используется параметр query). В качестве
	 * данных для поиска могут выступать email, телефон, имя. Возвращает массив данных контакта.
	 *
	 * @param array $attributes
	 * @return array|null
	 */
	public function findContact($attributes)
	{
		$contact = null;
		foreach ($attributes as $attribute) {
			$resultList = $this->client->contact->apiList([
				'query' => $attribute,
				'limit_rows' => 1,
			]);

			if (!empty($resultList)) {
				$contact = reset($resultList);
				break;
			}
		}

		return $contact;
	}

	/**
	 * Метод ищет контакт по id. Возвращает массив данных контакта.
	 *
	 * @param int $id
	 * @return array|null
	 */
	public function findContactById($id)
	{
		$contact = null;
		$resultList = $this->client->contact->apiList([
			'id' => $id,
			'limit_rows' => 1,
		]);

		if (!empty($resultList)) {
			$contact = reset($resultList);
		}

		return $contact;
	}

	/**
	 * Создает контакт с переданными параметрами. Не проверяет на дубль. Возвращает id созданной записи.
	 * @param $email
	 * @param $phone
	 * @param string $name
	 * @return array|int
	 */
	public function createContact($email, $phone, $name = '', $customFields = [])
	{
		$contact = $this->client->contact;
		$contact['name'] = $name;
		if (!empty($email)) {
			$contact->addCustomField($this->getEmailFieldId(), [
				[$email, 'WORK'],
			]);
		}
		if (!empty($phone)) {
			$contact->addCustomField($this->getPhoneFieldId(), [
				[$phone, 'WORK'],
			]);
		}
		if (!empty($customFields)) {
			foreach ($customFields as $fieldId => $fieldValue) {
				$contact->addCustomField($fieldId, $fieldValue);
			}
		}
		return $contact->apiAdd();
	}

	/**
	 * @param array $contactData
	 * @param string $email
	 * @param string $phone
	 * @param array $contactCustomFields
	 * @return bool
	 */
	protected function refreshContact($contactData, $email, $phone, $contactCustomFields = [])
	{
		$contactChanged = false;
		$contact = $this->client->contact;

		$contactChanged |= $this->addContactEmail($contactData, $contact, $email);
		$contactChanged |= $this->addContactPhone($contactData, $contact, $phone);
		if (!empty($contactCustomFields)) {
			$contactChanged = true;
			foreach ($contactCustomFields as $fieldId => $fieldValue) {
				$contact->addCustomField($fieldId, $fieldValue);
			}
		}
		if ($contactChanged) {
			try {
				$contact->apiUpdate($contactData['id']);
			} catch (\AmoCRM\Exception $exception) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array $contactData
	 * @param Contact $contactObj
	 * @param string $email
	 * @param string $emailType
	 * @return bool
	 */
	protected function addContactEmail($contactData, $contactObj, $email, $emailType = 'WORK')
	{
		if (!$this->hasContactThisEmail($contactData, $email)) {
			$emails = $this->normalizeContactPhonesOrEmails($this->getContactEmails($contactData, true), 'EMAIL');
			$emails[] = [$email, $emailType];
			$contactObj->addCustomField($this->getEmailFieldId(), $emails);
			return true;
		}
		return false;
	}

	/**
	 * @param array $contactData
	 * @param Contact $contactObj
	 * @param string $phone
	 * @param string $phoneType
	 * @return bool
	 */
	protected function addContactPhone($contactData, $contactObj, $phone, $phoneType = 'WORK')
	{
		if (!$this->hasContactThisPhone($contactData, $phone)) {
			$phones = $this->normalizeContactPhonesOrEmails($this->getContactPhones($contactData, true), 'PHONE');
			$phones[] = [$phone, $phoneType];
			$contactObj->addCustomField($this->getPhoneFieldId(), $phones);
			return true;
		}
		return false;
	}

	protected function getContactPhones($contactData, $rawValues = false)
	{
		return $this->getContactFieldValues($contactData, 'PHONE', $rawValues);
	}

	protected function getContactEmails($contactData, $rawValues = false)
	{
		return $this->getContactFieldValues($contactData, 'EMAIL', $rawValues);
	}

	protected function getContactFieldValues($contactData, $fieldCode, $rawValues = false)
	{
		$values = [];
		foreach ($contactData['custom_fields'] as $contactField) {
			if ($contactField['code'] == $fieldCode) {
				$values = $rawValues ? $contactField['values'] : array_column($contactField['values'], 'value');
				break;
			}
		}
		return $values;
	}

	protected function hasContactThisEmail($contactData, $email)
	{
		return in_array($email, $this->getContactEmails($contactData)) !== false;
	}

	protected function hasContactThisPhone($contactData, $phone)
	{
		return in_array($phone, $this->getContactPhones($contactData)) !== false;
	}

	protected function getEmailFieldId()
	{
		$fieldsIds = $this->fieldsIds();
		return isset($fieldsIds['email']) && $fieldsIds['email'] ? $fieldsIds['email'] : null;
	}

	protected function getPhoneFieldId()
	{
		$fieldsIds = $this->fieldsIds();
		return isset($fieldsIds['phone']) && $fieldsIds['phone'] ? $fieldsIds['phone'] : null;
	}

	protected function fieldsIds()
	{
		if (is_null($this->fieldsIds)) {
			$this->fieldsIds = [];
			$accountInfo = $this->client->account->apiCurrent();
			foreach ($accountInfo['custom_fields']['contacts'] as $field) {
				if ($field['code'] == 'PHONE') {
					$this->fieldsIds['phone'] = $field['id'];
				} else if ($field['code'] == 'EMAIL') {
					$this->fieldsIds['email'] = $field['id'];
				}
			}
		}
		return $this->fieldsIds;
	}

	protected function getEnumValues($fieldCode)
	{
		$accountInfo = $this->client->account->apiCurrent();
		foreach ($accountInfo['custom_fields']['contacts'] as $field) {
			if ($field['code'] == $fieldCode) {
				return $field['enums'];
			}
		}
		return [];
	}

	protected function normalizeContactPhonesOrEmails($data, $fieldCode) {
		$result = [];
		$enums = $this->getEnumValues($fieldCode);
		foreach ($data as $row) {
			$row = array_values($row);
			if (preg_match('/\d+/', $row[1])) {
				$row[1] = $enums[$row[1]];
			}
			$result[] = $row;
		}
		return $result;
	}
}
