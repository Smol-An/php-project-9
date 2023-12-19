<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Valitron\Validator;
use Carbon\Carbon;
use App\Connection;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('connection', function () {
    return Connection::get()->connect();
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'main.phtml');
})->setName('/');

$app->get('/urls', function ($request, $response) {
    $allDataFromDB = $this->get('connection')
      ->query('SELECT
                    urls.id,
                    urls.name,
                    max(url_checks.created_at) AS created_at
                FROM urls
                LEFT JOIN url_checks ON url_checks.url_id = urls.id
                GROUP BY urls.id
                ORDER BY urls.id DESC')
      ->fetchAll();

    $params = ['data' => $allDataFromDB];
    return $this->get('renderer')->render($response, 'urls/list.phtml', $params);
})->setName('urls');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $id = $args['id'];
    $flash = $this->get('flash')->getMessages();

    $selectAllQuery = 'SELECT * FROM urls WHERE id = :id';
    $selectAllStmt = $this->get('connection')->prepare($selectAllQuery);
    $selectAllStmt->execute([':id' => $id]);
    $dataFromDB = $selectAllStmt->fetch();

    $selectUrlChecksQuery = 'SELECT * FROM url_checks WHERE url_id = :id ORDER BY id DESC';
    $selectUrlChecksStmt = $this->get('connection')->prepare($selectUrlChecksQuery);
    $selectUrlChecksStmt->execute([':id' => $id]);
    $urlChecks = $selectUrlChecksStmt->fetchAll();

    $params = [
        'id' => $dataFromDB['id'],
        'name' => $dataFromDB['name'],
        'created_at' => $dataFromDB['created_at'],
        'urlChecks' => $urlChecks,
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
        $parsedUrl = parse_url($url['name']);
        $name = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";

        $selectIdQuery = 'SELECT id FROM urls WHERE name = :name';
        $selectIdStmt = $this->get('connection')->prepare($selectIdQuery);
        $selectIdStmt->execute([':name' => $name]);
        $idFromDB = $selectIdStmt->fetch();

        if (!empty($idFromDB)) {
            $this->get('flash')->addMessage('success', 'Страница уже существует');
            return $response->withRedirect($router->urlFor('url', ['id' => $idFromDB['id']]));
        }

        $created_at = Carbon::now();

        $insertDataQuery = 'INSERT INTO urls(name, created_at) VALUES(:name, :created_at)';
        $insertDataStmt = $this->get('connection')->prepare($insertDataQuery);
        $insertDataStmt->execute([':name' => $name, ':created_at' => $created_at]);

        $id = $this->get('connection')->lastInsertId();
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
        return $response->withRedirect($router->urlFor('url', ['id' => $id]));
    }

    $params = ['errors' => $errors];
    return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
});

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $url_id = $args['url_id'];

    $checkCreated_at = Carbon::now();

    $insertCheckQuery = 'INSERT INTO url_checks(url_id, created_at) VALUES(:url_id, :checkCreated_at)';
    $insertCheckStmt = $this->get('connection')->prepare($insertCheckQuery);
    $insertCheckStmt->execute([':url_id' => $url_id, ':checkCreated_at' => $checkCreated_at]);

    return $response->withRedirect($router->urlFor('url', ['id' => $url_id]), 302);
});

$app->run();
