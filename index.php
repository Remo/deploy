<?php

require_once __DIR__ . '/silex_private/autoload.php';
require_once __DIR__ . '/PdoProvider.php';


define('GIT_BINARY', '"C:\Program Files (x86)\Git\bin\git.exe"');

/**
 * Register application
 */
$app = new Silex\Application();
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
    //'twig.options' => array('cache' => __DIR__.'/silex_cache')
));

$app->register(new PdoServiceProvider(), array(
    'pdo.dsn' => 'mysql:dbname=deploy;host=127.0.0.1',
    'pdo.user' => 'deploy',
    'pdo.password' => 'deploy'
));

/**
 * Routes
 */
$app->get('/', function() use ($app) {

    $st = $app['pdo']->prepare("SELECT id, name, path, state FROM repositories ORDER BY name");
    $st->execute();

    $repositories = array();
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $repositories[] = $row;
    }   
    
    return $app['twig']->render('index.twig', array(
        'repositories' => $repositories
    ));
});


$app->post('/repository/update/{repository_id}', function($repository_id) use ($app) {
    $st = $app['pdo']->prepare("UPDATE repositories SET name=:name, path=:path, state=:state, local_path=:local_path WHERE id=:id");

    $st->execute(array(
        ':name' => $app['request']->get('name'),
        ':path' => $app['request']->get('path'),
        ':state' => $app['request']->get('state'),
        ':local_path' => $app['request']->get('local_path'),
        ':id' => $repository_id
    ));    
    
    return $app->redirect("/");
})->bind("update_repository");

$app->get('/repository/edit/{repository_id}', function($repository_id) use ($app) {
    $st = $app['pdo']->prepare("SELECT * FROM repositories WHERE id=:repository_id");
    $st->execute(array('repository_id' => $repository_id));

    $repository = $st->fetch(PDO::FETCH_ASSOC);
    
    $stTargets = $app['pdo']->prepare("SELECT id, name, server, username, password, directory, last_deployed_commit, date_last_deployment FROM deployment_targets WHERE repository_id=:repository_id ORDER BY name");
    $stTargets->execute(array('repository_id' => $repository_id));

    $targets = array();
    while ($row = $stTargets->fetch(PDO::FETCH_ASSOC)) {
        $targets[] = $row;
    }   
    
    return $app['twig']->render('repository_edit.twig', array(
        'repository' => $repository,
        'targets' => $targets
    ));    
})->bind("edit_repository");

$app->get('/repository/delete/{repository_id}', function($repository_id) use ($app) {

})->bind("delete_repository");

$app->get('/repository/deploy_target/{target_id}', function($target_id) use ($app) {
    
    $st = $app['pdo']->prepare("SELECT * FROM deployment_targets WHERE id=:target_id");
    $st->execute(array('target_id' => $target_id));

    $deployment_target = $st->fetch(PDO::FETCH_ASSOC);
    
    return $app['twig']->render('repository_deploy_target.twig', array(
        'deployment_target' => $deployment_target
    ));
})->bind("deploy_target");

$app->get('/repository/incremental_deploy/{target_id}/{commit_id}', function($target_id, $commit_id) use ($app) {
     
    $commit_id = preg_replace("/[^A-Za-z0-9]/", "", trim($commit_id));
     
    $st = $app['pdo']->prepare("SELECT dt.id, r.local_path FROM deployment_targets dt INNER JOIN repositories r ON r.id=dt.repository_id WHERE dt.id=:target_id");
    $st->execute(array('target_id' => $target_id));

    $deployment_target = $st->fetch(PDO::FETCH_ASSOC);
    
    $gitCloneDirectory = $deployment_target['local_path'];
    chdir($gitCloneDirectory);    
    
    exec(GIT_BINARY . ' pull');
    exec(GIT_BINARY . ' diff --name-status --no-renames ' . $commit_id, $output);

    $st = $app['pdo']->prepare("INSERT INTO deployment_queue (target_id, file, action) VALUES (:target_id, :file, 'upload')");
    foreach ($output as $line) {
        preg_match('/(.*)\t(.*)/', $line, $matches);
        if (count($matches) == 3) {
            $action = 'upload';
            if ($matches[1] == 'D') {
                $action = 'delete';
            }
            $st->execute(array('target_id' => $target_id, 'file' => $matches[2]));
        }
    }
    
    $st = $app['pdo']->prepare("UPDATE deployment_targets SET last_deployed_commit=:last_deployed_commit, date_last_deployment=now() WHERE id=:target_id");
    $st->execute(array('last_deployed_commit' => $commit_id, 'target_id' => $target_id));
    
    return $app['twig']->render('repository_initial_deploy.twig', array(
        'deployment_target' => $deployment_target
    ));       
})->bind("incremental_deploy");

$app->get('/repository/initial_deploy/{target_id}', function($target_id) use ($app) {

    $st = $app['pdo']->prepare("SELECT dt.id, r.local_path, r.path FROM deployment_targets dt INNER JOIN repositories r ON r.id=dt.repository_id WHERE dt.id=:target_id");
    $st->execute(array('target_id' => $target_id));

    $deployment_target = $st->fetch(PDO::FETCH_ASSOC);
    
    // clone repository
    $gitCloneDirectory = $deployment_target['local_path'];
       
    exec(GIT_BINARY . ' clone ' . $deployment_target['path'] . ' "' . $gitCloneDirectory . '"');
    
    chdir($gitCloneDirectory);
        
    // get all files from repository
    $files = array();
    
    exec(GIT_BINARY . ' ls-files', $files);

    $st = $app['pdo']->prepare("INSERT INTO deployment_queue (target_id, file, action) VALUES (:target_id, :file, 'upload')");
    foreach ($files as $file) {
        $st->execute(array(
            'target_id' => $target_id,
            'file' => $file
        ));
    }
    
    // get last commit id
    exec(GIT_BINARY . ' log -n 1 --pretty=format:%H', $output);
    $st = $app['pdo']->prepare("UPDATE deployment_targets SET last_deployed_commit=:last_deployed_commit, date_last_deployment=now() WHERE id=:target_id");
    $st->execute(array('last_deployed_commit' => $output[0], 'target_id' => $target_id));
        
    return $app['twig']->render('repository_initial_deploy.twig', array(
        'deployment_target' => $deployment_target
    ));    
   
})->bind("initial_deploy");

$app->post('/cronjob/get_deployment_status/{target_id}', function($target_id) use ($app) {
    header('Content-type: text/json');
    
    $st = $app['pdo']->prepare("SELECT state, count(*) c FROM deployment_queue WHERE target_id=:target_id GROUP BY state");
    $st->execute(array('target_id' => $target_id));

    $pending = 0;
    $deployed = 0;
    
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($row['state'] == 'pending') {
            $pending = $row['c'];
        }        
        else {
            $deployed = $row['c'];
        }
    }

    $ret = array(
        'finished' => $pending == 0,
        'total_files' => $deployed+$pending,
        'deployed_files' => $deployed
    );
    
    echo json_encode($ret);
    
})->bind("get_deployment_status");

$app->get('/cronjob/process_queue', function() use ($app) {
        
    $ftpConnections = array();
    $ftpDirectories = array();
    
    $st = $app['pdo']->prepare("SELECT dq.id, dq.file, dq.action, dt.id target_id, dt.server, dt.username, dt.password, dt.directory, r.local_path
        FROM deployment_queue dq
        INNER JOIN deployment_targets dt ON dq.target_id=dt.id
        INNER JOIN repositories r ON r.id=dt.repository_id
        WHERE dq.state='pending' ORDER BY dq.id LIMIT 0,100");
    $st->execute();

    $stUpdate = $app['pdo']->prepare("UPDATE deployment_queue SET state='deployed' WHERE id=:id");
    
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {

        $gitCloneDirectory = $row['local_path'];
        
        if (!array_key_exists($row['target_id'], $ftpConnections)) {
            $ftpConnections[$row['target_id']] = ftp_connect($row['server']);
            $ftpDirectories[$row['target_id']] = array();
            ftp_login($ftpConnections[$row['target_id']], $row['username'], $row['password']) or die("Couldn't connect to {$row['server']}");
        }
        
        if ($row['action'] == 'upload') {

            // check if remote directory exists
            $ftpRemoteDir = dirname($row['directory'] . $row['file']);
            if (!array_key_exists($ftpRemoteDir, $ftpDirectories[$row['target_id']])) {
                if (@ftp_chdir($ftpConnections[$row['target_id']], $ftpRemoteDir)) {
                    $ftpDirectories[$row['target_id']][$ftpRemoteDir] = $ftpRemoteDir;
                }
                else {                
                    $dirParts = explode("/", $ftpRemoteDir);
                    $fullpath = "";
                    foreach ($dirParts as $part) {                    
                        if (empty($part)) {
                            $fullpath .= "/";
                            continue;
                        }
                        $fullpath .= $part."/";
                        if (@ftp_chdir($ftpConnections[$row['target_id']], $fullpath)){
                           ftp_chdir($ftpConnections[$row['target_id']], $fullpath);
                        }
                        else {
                            if(@ftp_mkdir($ftpConnections[$row['target_id']], $part)){
                                ftp_chdir($ftpConnections[$row['target_id']], $part);
                            }
                        }
                    }
                }
            }

            // upload file
            ftp_put($ftpConnections[$row['target_id']], "{$row['directory']}{$row['file']}", "{$gitCloneDirectory}/{$row['file']}", FTP_BINARY);    
        
        }
        else {
            ftp_delete($ftpConnections[$row['target_id']], "{$row['directory']}{$row['file']}");
        }
        
        $stUpdate->execute(array('id'=>$row['id']));
    }
    
    foreach ($ftpConnections as $ftpConnectionID) {
        ftp_close($ftpConnectionID);
    }
    
});

$app->get('/repository/deploy/{repository_id}', function($repository_id) use ($app) {
    
    $st = $app['pdo']->prepare("SELECT id, name, server, username, directory, last_deployed_commit, date_last_deployment FROM deployment_targets WHERE repository_id=:repository_id ORDER BY name");
    $st->execute(array('repository_id' => $repository_id));

    $targets = array();
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $targets[] = $row;
    }   
    
    return $app['twig']->render('repository_deploy.twig', array(
        'repository_id' => $repository_id,
        'targets' => $targets
    ));
})->bind("deploy_repository");

$app['debug'] = true;

$app->run();
