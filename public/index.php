<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Valitron\Validator;
use Carbon\Carbon;
use App\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use DiDom\Document;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('connection', function () {
    $conn = new App\Connection();
    return $conn->connect();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'home.phtml');
})->setName('home');

$app->get('/urls', function ($request, $response) {
    $allUrlsData = $this->get('connection')
        ->query('SELECT id, name FROM urls ORDER BY id DESC')
        ->fetchAll(PDO::FETCH_ASSOC);

    $lastChecksData = $this->get('connection')
        ->query('SELECT
                url_id, 
                MAX(created_at) AS last_check_created_at,
                status_code 
            FROM url_checks 
            GROUP BY url_id, status_code')
        ->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(function ($url) use ($lastChecksData) {
        foreach ($lastChecksData as $check) {
            if ($url['id'] === $check['url_id']) {
                $url['last_check_created_at'] = $check['last_check_created_at'];
                $url['status_code'] = $check['status_code'];
            }
        }
        return $url;
    }, $allUrlsData);

    $params = ['data' => $data];
    return $this->get('renderer')->render($response, 'urls/list.phtml', $params);
})->setName('urls');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $id = $args['id'];
    $flash = $this->get('flash')->getMessages();

    $allIdData = $this->get('connection')
            ->query('SELECT id FROM urls')
            ->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array($id, $allIdData)) {
        return $this->get('renderer')->render($response->withStatus(404), 'error404.phtml');
    }

    $urlDataQuery = 'SELECT * FROM urls WHERE id = :id';
    $urlDataStmt = $this->get('connection')->prepare($urlDataQuery);
    $urlDataStmt->execute([':id' => $id]);
    $urlData = $urlDataStmt->fetch();

    $urlChecksQuery = 'SELECT * FROM url_checks WHERE url_id = :id ORDER BY id DESC';
    $urlChecksStmt = $this->get('connection')->prepare($urlChecksQuery);
    $urlChecksStmt->execute([':id' => $id]);
    $urlChecksData = $urlChecksStmt->fetchAll();

    $params = [
        'id' => $urlData['id'],
        'name' => $urlData['name'],
        'created_at' => $urlData['created_at'],
        'urlChecks' => $urlChecksData,
        'flash' => $flash
    ];
    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('url');

$app->post('/urls', function ($request, $response) use ($router) {
    $url = $request->getParsedBodyParam('url');

    $errors = [];
    $v = new Validator(['name' => $url['name']]);
    $v->rule('required', 'name')->rule('lengthMax', 'name', 255)->rule('url', 'name');

    if (empty($url['name'])) {
        $errors['name'] = 'URL не должен быть пустым';
    } elseif (!$v->validate()) {
        $errors['name'] = 'Некорректный URL';
    }

    if (empty($errors['name'])) {
        $parsedUrl = parse_url(strtolower($url['name']));
        $name = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";

        $urlIdQuery = 'SELECT id FROM urls WHERE name = :name';
        $urlIdStmt = $this->get('connection')->prepare($urlIdQuery);
        $urlIdStmt->execute([':name' => $name]);
        $urlId = $urlIdStmt->fetch();

        if (!empty($urlId)) {
            $this->get('flash')->addMessage('success', 'Страница уже существует');
            return $response->withRedirect($router->urlFor('url', ['id' => $urlId['id']]));
        }

        $created_at = Carbon::now();

        $newUrlQuery = 'INSERT INTO urls(name, created_at) VALUES(:name, :created_at)';
        $newUrlStmt = $this->get('connection')->prepare($newUrlQuery);
        $newUrlStmt->execute([':name' => $name, ':created_at' => $created_at]);

        $id = $this->get('connection')->lastInsertId();
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
        return $response->withRedirect($router->urlFor('url', ['id' => $id]));
    }

    $params = ['errors' => $errors];
    return $this->get('renderer')->render($response->withStatus(422), 'home.phtml', $params);
});

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $url_id = $args['url_id'];

    $urlNameQuery = 'SELECT name FROM urls WHERE id = :id';
    $urlNameStmt = $this->get('connection')->prepare($urlNameQuery);
    $urlNameStmt->execute([':id' => $url_id]);
    $urlName = $urlNameStmt->fetch();

    $client = new Client();

    try {
        $res = $client->request('GET', $urlName['name']);
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
        $status_code = $res->getStatusCode();
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('failure', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withRedirect($router->urlFor('url', ['id' => $url_id]));
    } catch (RequestException $e) {
        $res = $e->getResponse();
        $status_code = !is_null($res) ? $res->getStatusCode() : null;
        $check_created_at = Carbon::now();
        $newCheckQuery = 'INSERT INTO url_checks(
                    url_id,
                    status_code,
                    created_at
            ) VALUES(
                    :url_id,
                    :status_code,
                    :check_created_at
                )';
        $newCheckStmt = $this->get('connection')->prepare($newCheckQuery);
        $newCheckStmt->execute([
            ':url_id' => $url_id,
            ':status_code' => $status_code,
            ':check_created_at' => $check_created_at
        ]);
        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил c ошибкой');
        return $response->withRedirect($router->urlFor('url', ['id' => $url_id]));
    } catch (TransferException $e) {
        $this->get('flash')->addMessage('failure', $e->getMessage());
        return $response->withRedirect($router->urlFor('url', ['id' => $url_id]));
    }

    $document = new Document((string) $res->getBody());
    $h1 = $document->first('h1') ? mb_substr(optional($document->first('h1'))->text(), 0, 255) : '';
    $title = $document->first('title') ? mb_substr(optional($document->first('title'))->text(), 0, 255) : '';
    $description = $document->first('meta[name="description"]')
        ? mb_substr(optional($document->first('meta[name="description"]'))->getAttribute('content'), 0, 255)
        : '';

    $check_created_at = Carbon::now();

    $newCheckQuery = 'INSERT INTO url_checks(
                url_id,
                status_code,
                h1,
                title,
                description,
                created_at
        ) VALUES(
                :url_id,
                :status_code,
                :h1,
                :title,
                :description,
                :check_created_at
            )';
    $newCheckStmt = $this->get('connection')->prepare($newCheckQuery);
    $newCheckStmt->execute([
        ':url_id' => $url_id,
        ':status_code' => $status_code,
        ':h1' => $h1,
        ':title' => $title,
        ':description' => $description,
        ':check_created_at' => $check_created_at
    ]);

    return $response->withRedirect($router->urlFor('url', ['id' => $url_id]), 302);
});

$app->run();
