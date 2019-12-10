<?php

/*
  PufferPanel - A Game Server Management Panel
  Copyright (c) 2015 Dane Everitt

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program.  If not, see http://www.gnu.org/licenses/.
 */

namespace PufferPanel\Core;

use \ORM,
    \Exception,
    \Tracy\Debugger,
    \Unirest\Request;

$klein->respond('GET', '/admin/server', function($request, $response, $service) use ($core) {

    $servers = ORM::forTable('servers')->select('servers.*')->select('nodes.name', 'node_name')->select('users.email', 'user_email')
            ->select('nodes.ip', 'daemon_host')->select('nodes.daemon_listen', 'daemon_listen')
            ->join('users', array('servers.owner_id', '=', 'users.id'))
            ->join('nodes', array('servers.node', '=', 'nodes.id'))
            ->orderByDesc('active')
            ->findArray();
    
    $bearer = OAuthService::Get()->getPanelAccessToken();
    $header = array(
        'Authorization' => 'Bearer ' . $bearer
    );

    $serverIds = array();
    $nodes = array();
    foreach($servers as $server) {
        $serverIds[] = $server['hash'];
        $nodes[] = $server["daemon_host"] . ":" . $server["daemon_listen"];
    }
    foreach($servers as $server) {
        $serverIds[] = $server['hash'];
    }

    $ids = implode(",", $serverIds);
    $nodeConnections = array_unique($nodes);
    $results = array();
    
    foreach ($nodeConnections as $nodeConnection) {
        try {
            $unirest = Request::get(vsprintf(Daemon::buildBaseUrlForNode(explode(":", $nodeConnection)[0], explode(":", $nodeConnection)[1]) .'/network?ids=%s', array(
                        $ids)),
                        $header
            );
            if($unirest->body->success)
            {
                $results = array_merge($results, get_object_vars($unirest->body->data));
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
    }
    
    $newServers = array();
    
    foreach($servers as $server) {
        foreach($results as $key => $value) {
            if ($server['hash'] == $key) {
                $server['connection'] = $value;
            }
        }
        $newServers[] = $server;
    }    

    $response->body($core->twig->render('admin/server/find.html', array(
        'flash' => $service->flashes(),
        'servers' => $newServers
    )));
});

$klein->respond(array('GET', 'POST'), '/admin/server/view/[i:id]/[*]?', function($request, $response, $service, $app, $klein) use($core) {

    if (!$core->server->rebuildData($request->param('id'))) {

        if ($request->method('post')) {

            $response->body('A server by that ID does not exist in the system.');
        } else {

            $service->flash('<div class="alert alert-danger">A server by that ID does not exist in the system.</div>');
            $response->redirect('/admin/server');
        }

        $klein->skipRemaining();
    }

    if (!$core->user->rebuildData($core->server->getData('owner_id'))) {
        throw new Exception("This error should never occur. Attempting to access a server with an unknown user id.");
    }

    $response->cookie('accessToken', OAuthService::Get()->getAccessToken($core->user->getData('id'), $core->server->getData('id')));
});

$klein->respond('GET', '/admin/server/view/[i:id]', function($request, $response, $service) use ($core) {
    $response->body($core->twig->render('admin/server/view.html', array(
        'flash' => $service->flashes(),
        'node' => $core->server->nodeData(),
        'server' => $core->server->getData(),
        'user' => $core->user->getData())
    ));
});

$klein->respond('POST', '/admin/server/view/[i:id]/delete/[:force]?', function($request, $response, $service) use ($core) {

    // Start Transaction so if the daemon errors we can rollback changes
    $bearer = OAuthService::Get()->getPanelAccessToken();
    ORM::get_db()->beginTransaction();

    $node = ORM::forTable('nodes')->findOne($core->server->getData('node'));

    ORM::forTable('subusers')->where('server', $core->server->getData('id'))->deleteMany();
    ORM::forTable('permissions')->where('server', $core->server->getData('id'))->deleteMany();
    $clientIds = ORM::forTable('oauth_clients')->where('server_id', $core->server->getData('id'))->select('id')->findMany();
    foreach ($clientIds as $id) {
        ORM::forTable('oauth_access_tokens')->where('oauthClientId', $id->id)->deleteMany();
    }
    ORM::forTable('oauth_clients')->where('server_id', $core->server->getData('id'))->deleteMany();
    ORM::forTable('servers')->where('id', $core->server->getData('id'))->deleteMany();

    try {

        $header = array(
            'Authorization' => 'Bearer ' . $bearer
        );

        $updatedUrl = sprintf('%s/server/%s', Daemon::buildBaseUrlForNode($node->ip, $node->daemon_listen), $core->server->getData('hash'));

        try {
            $unirest = Request::delete($updatedUrl, $header);
        } catch (\Exception $ex) {
            throw $ex;
        }
        if ($unirest->body->success) {
            ORM::get_db()->commit();
            $service->flash('<div class="alert alert-success">The requested server has been deleted from PufferPanel.</div>');
        } else {
            throw new Exception('<div class="alert alert-danger">The daemon returned an error when trying to process your request. Daemon said: ' . $unirest->body->msg . ' [' . $unirest->body->code . ']</div>');
        }
    } catch (Exception $e) {

        Debugger::log($e);

        if ($request->param('force') && $request->param('force') === "force") {

            ORM::get_db()->commit();

            $service->flash('<div class="alert alert-danger">An error was encountered with the daemon while trying to delete this server from the system. <strong>Because you requested a force delete this server has been removed from the panel regardless of the reason for the error. This server and its data may still exist on the pufferd instance.</strong></div>');
        } else {

            ORM::get_db()->rollBack();
            $service->flash('<div class="alert alert-danger">An error was encountered with the daemon while trying to delete this server from the system.</div>');
            $response->redirect('/admin/server/view/' . $request->param('id') . '?tab=delete');

        }
    }
    $response->redirect('/admin/server');
});

$klein->respond('GET', '/admin/server/new', function($request, $response, $service) use ($core) {

    $response->body($core->twig->render('admin/server/new.html', array(
        'locations' => ORM::forTable('locations')->findMany(),
        'flash' => $service->flashes())
    ));
});

$klein->respond('GET', '/admin/server/accounts/[:email]', function($request, $response) use ($core) {

    $select = ORM::forTable('users')->where_raw('email LIKE ? OR username LIKE ?', array('%' . $request->param('email') . '%', '%' . $request->param('email') . '%'))->findMany();

    $resp = array();
    foreach ($select as $select) {

        $resp = array_merge($resp, array(array(
                'email' => $select->email,
                'username' => $select->username,
                'hash' => md5($select->email)
        )));
    }

    $response->json(array('accounts' => $resp));
});

$klein->respond('POST', '/admin/server/new', function($request, $response, $service) use($core) {

    setcookie('__temporary_pp_admin_newserver', base64_encode(json_encode($_POST)), time() + 60);
    $bearer = OAuthService::Get()->getPanelAccessToken();
    ORM::get_db()->beginTransaction();

    $node = ORM::forTable('nodes')->findOne($request->param('node'));

    if (!$node) {

        $service->flash('<div class="alert alert-danger">The selected node does not exist on the system.</div>');
        $response->redirect('/admin/server/new');
        return;
    }

    if (!preg_match('/^[\w -]{4,35}$/', $request->param('server_name'))) {

        $service->flash('<div class="alert alert-danger">The name provided for the server did not meet server requirements. Server names must be between 4 and 35 characters long and contain no special characters.</div>');
        $response->redirect('/admin/server/new');
        return;
    }

    $user = ORM::forTable('users')->select('id')->select('root_admin')->where('email', $request->param('email'))->findOne();

    if (!$user) {

        $service->flash('<div class="alert alert-danger">The email provided does not match any account in the system.</div>');
        $response->redirect('/admin/server/new');
        return;
    }

    $existingName = ORM::for_table('servers')->where('name', $request->param('server_name'))->find_one();
    if ($existingName) {
        $service->flash('<div class="alert alert-danger">That name is already in use by another server, please enter another name</div>');
        $response->redirect('/admin/server/new');
        return;
    }

    $server_hash = $core->auth->generateUniqueUUID('servers', 'hash');
    $daemon_secret = $core->auth->generateUniqueUUID('servers', 'daemon_secret');

    $server = ORM::forTable('servers')->create();
    $server->set(array(
        'hash' => $server_hash,
        'daemon_secret' => $daemon_secret,
        'node' => $request->param('node'),
        'name' => $request->param('server_name'),
        'owner_id' => $user->id(),
        'date_added' => time(),
    ));
    $server->save();

    OAuthService::Get()->create($user->id(),
            $server->id(),
            '.internal_' . $user->id() . '_' . $server->id(),
            $user->root_admin ? OAuthService::getUserScopes() . ' ' . OAuthService::getAdminScopes() : OAuthService::getUserScopes(),
            'internal_use',
            'internal_use'
    );

    //add admins to server
    $adminUsers = ORM::forTable('users')->select('id')->where('root_admin', 1)->whereNotEqual('id', $user->id())->findMany();
    foreach($adminUsers as $k => $adminUser) {
        OAuthService::Get()->create($adminUser->id(),
            $server->id(),
            '.internal_' . $adminUser->id() . '_' . $server->id(),
            OAuthService::getUserScopes() . " " . OAuthService::getAdminScopes(),
            'internal_use',
            'internal_use'
        );
    }

    /*
     * Build Call
     */
    $createData = array(
        "name" => $server_hash,
        "type" => $request->param('plugin'),
        "data" => array(),
        "environment" => array()
    );

    $ignoredFields = array(
        "location", "plugin", "node", "server_name", "email"
    );

    foreach($request->paramsPost() as $k => $value) {
        if(in_array($k, $ignoredFields, false)) {
            continue;
        }
        $createData["data"][$k] = $value;
    }

    try {

        $header = array(
            'Authorization' => 'Bearer ' . $bearer
        );

        $unirest = Request::put(Daemon::buildBaseUrlForNode($node->ip, $node->daemon_listen) . '/server/' . $server_hash, $header, json_encode($createData));

        if (!$unirest->body->success) {
            throw new \Exception("An error occurred trying to add a server. (" . $unirest->body->msg . ") [" . $unirest->body->code . "]");
        }

        ORM::get_db()->commit();
    } catch (\Exception $e) {

        ORM::get_db()->rollBack();

        $service->flash('<div class="alert alert-danger">An error occurred while trying to connect to the remote node. Please check that the daemon is running and try again.<br />' . $e->getMessage() . '</div>');
        $response->redirect('/admin/server/new');
        return;
    }

    $service->flash('<div class="alert alert-success">Server created successfully.</div>');
    $response->redirect('/admin/server/view/' . $server->id());
    
    //have daemon install server
    try {
        Request::post(Daemon::buildBaseUrlForNode($node->ip, $node->daemon_listen) . '/server/' . $server_hash . '/install', $header, json_encode($data));
    } catch (\Exception $ex) {
    } catch (\Throwable $ex) {
    }
});

$klein->respond('POST', '/admin/server/new/node-list', function($request, $response) use($core) {

    $response->body($core->twig->render('admin/server/node-list.html', array(
                'nodes' => ORM::forTable('nodes')->where('location', $request->param('location'))->findMany()
    )));
});

$klein->respond('GET', '/admin/server/new/plugins', function($request, $response) {

    $node = ORM::forTable('nodes')->findOne($request->param('node'));

    $unirest = null;

    $bearer = OAuthService::Get()->getPanelAccessToken();
    $header = array(
        'Authorization' => 'Bearer ' . $bearer
    );

    try {
        $unirest = Request::get(Daemon::buildBaseUrlForNode($node->ip, $node->daemon_listen) . '/_templates', $header, nil);
    } catch (\Exception $ex) {
        $response->code(503);
        return;
    }
    $response->json($unirest->body);
});
