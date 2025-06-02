<?php
require_once __DIR__ . '/../vendor/autoload.php';

$httpClient = new GuzzleHttp\Client;
$fetchRange = 'Лист1!A1:D1000';

$config = require __DIR__ . '/../config/conf.php';
$client = new Google\Client();
$client->setApplicationName("tables test");
$client->setAuthConfig( __DIR__ . '/../config/auth/client_secret.json');
$client->setAccessType('offline');
$client->addScope(Google_Service_Sheets::SPREADSHEETS);
authenticateGoogle($client);
$service = new Google_Service_Sheets($client);

$gSheetData = fetchDataFromTable($config, $service, $fetchRange);

//проверка токена
$amoTokens = []; 
if(file_exists(__DIR__ . '/../config/auth/amo_token.json')) {
    $amoTokens = json_decode(file_get_contents(__DIR__ . '/../config/auth/amo_token.json'), true);
    try {
        $httpClient->get('https://' . $config['subdomain'] . '.amocrm.ru/api/v4/account', [
            'headers' => [
                'Authorization' => 'Bearer ' . $amoTokens['access_token'],
            ]
        ]);
    } catch (GuzzleHttp\Exception\RequestException $e) {
        if ($e->getResponse()->getStatusCode() === 401) {
            print "ошибка, пробуем обновить access_token" . PHP_EOL;
            refreshAccessToken($config, $httpClient);
        } else {
            print "ошибка: " . $e->getMessage();
        }
    }
} else {
    initialAmoAuth($config, $httpClient);
    $amoTokens = json_decode(file_get_contents(__DIR__ . '/../config/auth/amo_token.json'), true);
}

writeToTable($config, $gSheetData, $service, $httpClient, $amoTokens);




function fetchDataFromTable($config, $service, $range) {
    try {
        $result = $service->spreadsheets_values->get($sheetId, $range)->getValues();
        // $numRows = $result->getValues() !== null ? count($result->getValues()) : 0;
        // printf("%d rows retrieved.", $numRows);
        return $result;
    } catch(Exception $e) {
        print 'ошибка получения данных с таблицы: ' . $e->getMessage();
        die;
    }
}

function executeRequestToAmo($httpClient, $url, $amoTokens) {
    try {
        $response = $httpClient->get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $amoTokens['access_token'],
            ]
        ]);

        return $response->getBody();
    } catch (GuzzleHttp\Exception\RequestException $e) {
        print "ошибка получения ответа с amo: " . $e->getMessage() . PHP_EOL;
        return false;
    }
}

function executeGsheetUpdateRequest($service, $payload) {
    try {
        $service->spreadsheets_values->update($payload['spreadsheetId'], $payload['insertAdress'], $payload['dataToInsert'], ['valueInputOption' => 'RAW']);
    } catch (Exception $e) {
        print "ошибка вставки значения в таблицу" . $e->getMessage() . PHP_EOL;
        return false;
    }
}

//функция одновременно делает запрос по юрлу из таблицы и делает post запрос в гугл апи
function writeToTable($config, $service, $httpClient, $amoTokens, $rowsArr) {

    $weekdayName = date('l');
    $spreadsheetId = $config['sheetId'];
    $range = "Лист1!A1:D2";

    foreach($rowsArr as $row) {
        $url = $row[0];
        $pathToTargetData = $row[1];
        $insertAdress = $row[2];
        $days = $row[3];
        $daysArr = explode(',', $days);

        if(in_array($weekdayName, $daysArr) || true) {
            
            $parts = parse_url($url);
            $query = $parts['query'] ?? '';
            parse_str($query, $params);

            $from = $params['filter_date_from'] ?? null;
            $to = $params['filter_date_to'] ?? null;

            $params['filter_date_from'] = parseDate($from);
            $params['filter_date_to'] = parseDate($to);
            $newUrl = rebuildUrl($parts, $params);

            $response = executeRequestToAmo($httpClient, $newUrl, $amoTokens);
            if($response === false) {
                continue;
            }
 
            $dataToInsert = accessNestedValue($response, $pathToTargetData);

            $dataToInsert = new Google_Service_Sheets_ValueRange([
                'values' => [[$dataToInsert]], //передаваемое поле должно быть массивом из массивов
            ]);

            $payload = [
                'spreadsheetId' => $spreadsheetId,
                'insertAdress' => $insertAdress,
                'dataToInsert' => $dataToInsert,
            ];

            executeGsheetUpdateRequest($service, $payload);
        }
    }
}

function authenticateGoogle($client) {
    $authTokenPath = __DIR__ . '/../config/auth/token.json';

    if(file_exists($authTokenPath)) { //проверяем есть ли токен
        $authToken = json_decode(file_get_contents($authTokenPath), true);
        $client->setAccessToken($authToken); 
    }

    if($client->isAccessTokenExpired()) { //если нету - пробуем поулчить
        if($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            $authUrl = $client->createAuthUrl();
            print'откройте ссылку: ' . $authUrl . PHP_EOL;
            print'введите код, он будет находится в адресной строке в параметре code:' . PHP_EOL;
            $authCode = trim(fgets(STDIN));

            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            if (!file_exists(dirname($authTokenPath))) {
                mkdir(dirname($authTokenPath), 0777, true);
            }
            file_put_contents($authTokenPath, json_encode($accessToken));
        }
    }
}

function initialAmoAuth($config, $httpClient) {

    $amoAuthLink = 'https://www.amocrm.ru/oauth?client_id=' . $config['client_id'] . '&state=statexample&mode=post_message&redirect_uri=' . $config['redirect_uri'];
    print 'откройте ссылку: ' . $amoAuthLink . PHP_EOL;
    print 'введите код, он будет находится в адресной строке в параметре code:' . PHP_EOL;

    $amoAuthCode = trim(fgets(STDIN));

    $amoAuthUrl = "https://" . $config['subdomain'] . ".amocrm.ru/oauth2/access_token";

    try {
        $response = $httpClient->post($amoAuthUrl, [
            'json' => [
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type'    => 'authorization_code',
                'code'          => $amoAuthCode,
                'redirect_uri'  => $config['redirect_uri'],
            ]
        ]);

        $tokens = json_decode($response->getBody()->getContents(), true);
        
        file_put_contents(__DIR__ . '/../config/auth/amo_token.json', json_encode($tokens, JSON_PRETTY_PRINT));

    } catch (RequestException $e) {
        if ($e->hasResponse()) {
            print "ошибка: " . $e->getResponse()->getBody();
        } else {
            print "ошибка: " . $e->getMessage();
        }
        print 'общая ошибка авторизации амо';
        die;
    }
}

function refreshAccessToken($config, $httpClient) {

    $amoAuthUrl = "https://" . $config['subdomain'] . ".amocrm.ru/oauth2/access_token";
    $refreshToken = json_decode(file_get_contents(__DIR__ . '/../config/auth/amo_token.json'), true)['refresh_token'];

    try {
        $response = $httpClient->post($amoAuthUrl, [
            'json' => [
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'redirect_uri'  => $config['redirect_uri'],
            ]
        ]);

        $tokens = json_decode($response->getBody()->getContents(), true);
        
        file_put_contents(__DIR__ . '/../config/auth/amo_token.json', json_encode($tokens, JSON_PRETTY_PRINT));

    } catch (RequestException $e) {
        if ($e->hasResponse()) {
            print "ошибка: " . $e->getResponse()->getBody();
        } else {
            print "ошибка: " . $e->getMessage();
        }

        //что-то не так с рефреш токеном, пробуем получить пару заново 
        initialAmoAuth();
    }
}

function accessNestedValue($data, $path) {
    $keys = explode('->', $path);
    $current = $data;
    
    foreach ($keys as $key) {
        if (is_object($current)) {
            $current = $current->$key ?? null;
        } elseif (is_array($current)) {
            $current = $current[$key] ?? null;
        } else {
            return null;
        }
    }
    
    return $current;
}

function parseDate($value) { 
    $value = trim($value);
    if (is_string($value) && strpos($value, 'day') !== false) {
        if (preg_match('/(-?\d+)\s*day/', $value, $matches)) {
            $offset = (int)$matches[1];
            $date = new DateTime();
            $date->modify("$offset days");
            return $date->format('d.m.Y');
        }
    }

    $parsedDate = DateTime::createFromFormat('d.m.Y', $value);
    $parsedDate = $parsedDate->format('d.m.Y');

    if($parsedDate == $value) {
        return $value;
    } else {
        return false;
    }
}

function rebuildUrl($parts, $params) {
    $newQuery = http_build_query($params);

    $newUrl =
    ($parts['scheme'] ?? 'https') . '://' .
    ($parts['host'] ?? '') .
    ($parts['path'] ?? '') . '?' .
    $newQuery;

    return $newUrl;
}