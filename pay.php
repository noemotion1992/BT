<?php

use Ofey\Logan22\component\alert\board;
use Ofey\Logan22\component\lang\lang;
use Ofey\Logan22\controller\config\config;
use Ofey\Logan22\model\donate\donate;
use Ofey\Logan22\model\user\user;

class betatransfer extends \Ofey\Logan22\model\donate\pay_abstract {

    protected static bool $enable = true;
    protected static bool $forAdmin = false;

    const BASE_URL_V1 = 'https://merchant.betatransfer.io/';
    const BASE_URL_V2 = 'https://api.betatransfer.io/';
	
	// Добавляем в класс betatransfer
	private const COUNTRY_PAYMENT_MAP = [
		'UA' => 'Card1', // Украина
		'US' => 'Card2', // США
		'PL' => 'Card3', // Польша
		'DE' => 'Card4', // Германия
		'FR' => 'Card5', // Франция
		'IT' => 'Card6', // Италия
		'ES' => 'Card7', // Испания
	];

// Получаем paymentSystem по коду страны
private function getPaymentSystemByCountry(string $countryCode): string {
    return self::COUNTRY_PAYMENT_MAP[$countryCode] ?? 'Card7'; // По умолчанию Card7
}

    private string $logFile = __DIR__ . '/logs/betatransfer.log';

    public static function inputs(): array {
        return [
            'public_api_key' => '',
            'secret_api_key' => '',
        ];
    }

    private function log(string|array $message): void {
        if (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        file_put_contents($this->logFile, "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL, FILE_APPEND);
    }

    private function request(
        string $url,
        array $data = [],
        array $headers = [],
        string $method = 'POST'
    ): array {
        $this->log(["Request URL" => $url, "Method" => $method, "Data" => $data, "Headers" => $headers]);

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Увеличиваем таймаут
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Включаем проверку SSL
        
        // Для всех методов кроме GET устанавливаем метод запроса
        if (strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } elseif (strtoupper($method) === 'JSON') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        
        $this->log("Raw response: " . $response);
        
        // Пытаемся декодировать ответ, независимо от HTTP кода
        $decoded = json_decode($response, true);
        
        curl_close($ch);

        $this->log([
            "HTTP Code" => $httpCode,
            "Curl Error" => $curlError,
            "Decoded Response" => $decoded
        ]);

        return [
            'code' => $httpCode,
            'error' => $curlError,
            'errno' => $curlErrno,
            'body' => $decoded,
            'raw' => $response // Добавляем сырой ответ для отладки
        ];
    }

    private function generateSignV1(array $options): string {
        // По документации: подпись генерируется из всех переданных параметров в том же порядке
        // и конкатенируется с API Secret
        // $sign = md5(implode("", $params) . $apiSecret);
        
        // Важно: удаляем 'sign' из массива, если он там есть
        if (isset($options['sign'])) {
            unset($options['sign']);
        }
        
        // Строим строку из значений всех параметров в порядке их появления
        $signString = implode('', $options);
        $sign = md5($signString . self::getConfigValue('secret_api_key'));
        
        $this->log("Parameters for sign: " . json_encode($options, JSON_UNESCAPED_UNICODE));
        $this->log("Sign string: $signString");
        $this->log("Generated sign: $sign");
        
        return $sign;
    }

    public function payment(
        string $amount,
        string $currency,
        string $orderId,
        array $options = []
    ): array {
        // Правильное форматирование суммы
        $formattedAmount = number_format((float)$amount, 2, '.', '');
        
        // Не удаляем подчеркивание из orderId, оно допустимо по документации
        // "только латинские буквы, цифры, без спецсимволов"
        $safeOrderId = $orderId;
        
        $requestData = array_merge($options, [
            'amount' => $formattedAmount,
            'currency' => $currency,
            'orderId' => $safeOrderId,
            'fullCallback' => 0,
        ]);
        
        // Генерация подписи должна быть после формирования всех параметров
        $requestData['sign'] = $this->generateSignV1($requestData);
        
        // Добавление токена в URL (GET параметр)
        $query = http_build_query([
            'token' => self::getConfigValue('public_api_key')
        ]);
        
        $url = rtrim(self::BASE_URL_V1, '/') . '/api/payment?' . $query;
        
        // Правильно устанавливаем заголовки для application/x-www-form-urlencoded
        $headers = [
            'Content-Type: application/x-www-form-urlencoded'
        ];
        
        return $this->request($url, $requestData, $headers, 'POST');
    }

    public function create_link(): void {
		user::self()->isAuth() ?: board::notice(false, lang::get_phrase(234));
		donate::isOnlyAdmin(self::class);
	
		if (empty(self::getConfigValue('public_api_key')) || empty(self::getConfigValue('secret_api_key'))) {
			board::error("betatransfer token is empty");
		}
	
		$count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT);
		if (!$count) {
			board::notice(false, "Введите сумму цифрой");
		}
	
		// Получаем выбранную страну
		$country = filter_input(INPUT_POST, 'country', FILTER_SANITIZE_STRING);
		if (!$country) {
			board::notice(false, "Выберите страну");
		}
	
		$donate = \Ofey\Logan22\model\server\server::getServer(user::self()->getServerId())->donate();
		$amount = self::sphereCoinSmartCalc($count, $donate->getRatioUSD(), $donate->getSphereCoinCost());
	
		if ($amount < 10) {
			board::notice(false, "Минимальное пополнение от 10 USD");
		}
		if ($amount > 500) {
			board::notice(false, "Максимальное пополнение до 500 USD");
		}
	
		$orderId = user::self()->getId() . '_' . mt_rand(0, 999999);
		$this->log("Создание платежа: amount={$amount}, orderId={$orderId}");
	
		// Определяем paymentSystem по стране
		$paymentSystem = $this->getPaymentSystemByCountry($country);
	
		$response = $this->payment((string)round($amount, 1), 'USD', $orderId, [
			'paymentSystem' => $paymentSystem, // Теперь зависит от страны
		]);
	
		// Остальной код без изменений
		if ($response['code'] === 422) {
			$this->log("Ошибка валидации запроса 422: " . ($response['raw'] ?? 'No raw response'));
			board::error("Ошибка валидации запроса. Проверьте лог для деталей.");
			return;
		}
	
		if (!empty($response['body'])) {
			$body = $response['body'];
			if (isset($body['status']) && $body['status'] === 'success') {
				$this->log("Успешный платёж: " . ($body['url'] ?? 'No URL'));
				echo $body['url'] ?? '';
			} else {
				$errorMessage = '';
				if (isset($body['errors']) && is_array($body['errors'])) {
					foreach ($body['errors'] as $field => $errors) {
						if (is_array($errors)) {
							$errorMessage .= "$field: " . implode(', ', $errors) . "; ";
						} else {
							$errorMessage .= "$field: $errors; ";
						}
					}
				} else {
					$errorMessage = $body['error'] ?? 'Произошла неизвестная ошибка';
				}
				$this->log("Ошибка оплаты: $errorMessage");
				board::error("Ошибка: $errorMessage");
			}
		} else {
			$this->log("Ошибка: пустой ответ или невозможно декодировать JSON. HTTP код: " . $response['code']);
			board::error("Ошибка: не удалось получить ответ от сервера. HTTP код: " . $response['code']);
		}
	}


    public function webhook(): void {
    if (!(config::load()->donate()->getDonateSystems('betatransfer')?->isEnable() ?? false)) {
        echo 'disabled';
        return;
    }

    $this->log("Входящий webhook: " . print_r($_POST, true));

    if (empty(self::getConfigValue('public_api_key')) || empty(self::getConfigValue('secret_api_key'))) {
        $this->log("Ошибка: пустые ключи API");
        board::error("betatransfer token is empty");
    }

    $sign = $_POST['sign'] ?? null;
    $amount = (float)($_POST['amount'] ?? 0);
    $orderId = $_POST['orderId'] ?? null;
    $currency = $_POST['currency'] ?? "USD";  // Изменено на USD

    if ($sign && $amount && $orderId && $this->callbackSignIsValid($sign, $amount, $orderId)) {
        // Извлекаем ID пользователя из orderId
        $orderParts = explode("_", $orderId);
        $userId = isset($orderParts[0]) ? (int)$orderParts[0] : 0;
        
        if (!$userId) {
            $this->log("Ошибка: не удалось извлечь ID пользователя из orderId: $orderId");
            die('FAIL: Invalid orderId');
        }

        donate::control_uuid($sign, get_called_class());
        $convertedAmount = donate::currency($amount, $currency);

        $user = user::getUserId($userId);
        self::telegramNotice($user, $amount, $currency, $convertedAmount, get_called_class());
        $user->donateAdd($convertedAmount)->AddHistoryDonate(amount: $convertedAmount, pay_system: get_called_class());
        donate::addUserBonus($userId, $convertedAmount);

        $this->log("Успешное начисление доната пользователю $userId на сумму $convertedAmount");
        die('OK');
    }

    $this->log("Ошибка валидации webhook.");
    die('FAIL');
}

    public function callbackSignIsValid(string $sign, float $amount, string $orderId): bool {
        $expected = md5($amount . $orderId . self::getConfigValue('secret_api_key'));
        $valid = $sign === $expected;
        $this->log("Проверка подписи webhook: ожидалось [$expected], получено [$sign], результат: " . ($valid ? 'OK' : 'FAIL'));
        return $valid;
    }
}