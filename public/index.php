<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Routing\RouteContext;
use DI\Container;
use Valitron\Validator;
use Carbon\Carbon;
use App\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use DiDom\Document;

session_start();

$container = new Container();

$app = AppFactory::createFromContainer($container);

$app->add(function (Request $request, RequestHandler $handler) use ($container) {
    $routeContext = RouteContext::fromRequest($request);
    $route = $routeContext->getRoute();

    $routeName = !empty($route) ? $route->getName() : '';
    $container->set('routeName', $routeName);

    return $handler->handle($request);
});

$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$container->set('router', $app->getRouteCollector()->getRouteParser());

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('connection', function () {
    $conn = new App\Connection();
    return $conn->connect();
});
$container->set('renderer', function () use ($container) {
    $templateVariables = [
        'routeName' => $container->get('routeName'),
        'router' => $container->get('router'),
        'flash' => $container->get('flash')->getMessages()
    ];
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates', $templateVariables);
});

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
        'urlChecks' => $urlChecksData
    ];
    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('url');

$app->post('/urls', function ($request, $response) {
    $url = $request->getParsedBodyParam('url');

    $v = new Validator(['url_name' => $url['name']]);
    $v->rule('required', 'url_name')->message('URL не должен быть пустым');
    $v->rule('lengthMax', 'url_name', 255)->message('URL превышает 255 символов');
    $v->rule('url', 'url_name')->message('Некорректный URL');

    if (!$v->validate()) {
        $params = [
            'url' => $url['name'],
            'errors' => $v->errors()
        ];
        return $this->get('renderer')->render($response->withStatus(422), 'home.phtml', $params);
    }

    $parsedUrl = parse_url(strtolower($url['name']));
    $name = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";

    $urlIdQuery = 'SELECT id FROM urls WHERE name = :name';
    $urlIdStmt = $this->get('connection')->prepare($urlIdQuery);
    $urlIdStmt->execute([':name' => $name]);
    $urlId = $urlIdStmt->fetch();

    if (!empty($urlId)) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect($this->get('router')->urlFor('url', ['id' => $urlId['id']]));
    }

    $created_at = Carbon::now();

    $newUrlQuery = 'INSERT INTO urls(name, created_at) VALUES(:name, :created_at)';
    $newUrlStmt = $this->get('connection')->prepare($newUrlQuery);
    $newUrlStmt->execute([':name' => $name, ':created_at' => $created_at]);

    $id = $this->get('connection')->lastInsertId();
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response->withRedirect($this->get('router')->urlFor('url', ['id' => $id]));
});

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) {
    $url_id = $args['url_id'];

    $urlNameQuery = 'SELECT name FROM urls WHERE id = :id';
    $urlNameStmt = $this->get('connection')->prepare($urlNameQuery);
    $urlNameStmt->execute([':id' => $url_id]);
    $urlName = $urlNameStmt->fetch();

    $client = new Client();

    try {
        $res = $client->request('GET', $urlName['name'], ['allow_redirects' => false]);
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (ClientException $e) {
        $res = $e->getResponse();
        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил c ошибкой');
        return $response->withRedirect($this->get('router')->urlFor('url', ['id' => $url_id]));
    } catch (ServerException $e) {
        $res = $e->getResponse();
        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил c ошибкой');
        return $response->withRedirect($this->get('router')->urlFor('url', ['id' => $url_id]));
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('failure', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withRedirect($this->get('router')->urlFor('url', ['id' => $url_id]));
    } catch (TransferException $e) {
        $this->get('flash')->addMessage('failure', 'Упс что-то пошло не так');
        return $response->withRedirect($this->get('router')->urlFor('url', ['id' => $url_id]));
    }

    $status_code = $res->getStatusCode();

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

    return $response->withRedirect($this->get('router')->urlFor('url', ['id' => $url_id]));
});

$app->run();
