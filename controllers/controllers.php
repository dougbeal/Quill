<?php

function require_login(&$app, $redirect=true) {
  $params = $app->request()->params();
  if(array_key_exists('token', $params)) {
    try {
      $data = JWT::decode($params['token'], Config::$jwtSecret);
      $_SESSION['user_id'] = $data->user_id;
      $_SESSION['me'] = $data->me;
    } catch(DomainException $e) {
      if($redirect) {
        header('X-Error: DomainException');
        $app->redirect('/', 301);
      } else {
        return false;
      }
    } catch(UnexpectedValueException $e) {
      if($redirect) {
        header('X-Error: UnexpectedValueException');
        $app->redirect('/', 301);
      } else {
        return false;
      }
    }
  }

  if(!array_key_exists('user_id', $_SESSION)) {
    if($redirect)
      $app->redirect('/');
    return false;
  } else {
    return ORM::for_table('users')->find_one($_SESSION['user_id']);
  }
}

function generate_login_token() {
  return JWT::encode(array(
    'user_id' => $_SESSION['user_id'],
    'me' => $_SESSION['me'],
    'created_at' => time()
  ), Config::$jwtSecret);
}

$app->get('/new', function() use($app) {
  if($user=require_login($app)) {

    $entry = false;
    $photo_url = false;

    $test_response = '';
    if($user->last_micropub_response) {
      try {
        if(@json_decode($user->last_micropub_response)) {
          $d = json_decode($user->last_micropub_response);
          $test_response = $d->response;
        }
      } catch(Exception $e) {
      }
    }

    $html = render('new-post', array(
      'title' => 'New Post',
      'micropub_endpoint' => $user->micropub_endpoint,
      'micropub_scope' => $user->micropub_scope,
      'micropub_access_token' => $user->micropub_access_token,
      'response_date' => $user->last_micropub_response_date,
      'syndication_targets' => json_decode($user->syndication_targets, true),
      'test_response' => $test_response,
      'location_enabled' => $user->location_enabled
    ));
    $app->response()->body($html);
  }
});


$app->get('/bookmark', function() use($app) {
  if($user=require_login($app)) {
    $params = $app->request()->params();

    $url = '';
    $name = '';
    $content = '';
    $tags = '';

    if(array_key_exists('url', $params))
      $url = $params['url'];

    if(array_key_exists('name', $params))
      $name = $params['name'];

    if(array_key_exists('content', $params))
      $content = $params['content'];

    $html = render('new-bookmark', array(
      'title' => 'New Bookmark',
      'bookmark_url' => $url,
      'bookmark_name' => $name,
      'bookmark_content' => $content,
      'bookmark_tags' => $tags,
      'token' => generate_login_token(),
      'syndication_targets' => json_decode($user->syndication_targets, true)
    ));
    $app->response()->body($html);
  }
});

$app->get('/favorite', function() use($app) {
  if($user=require_login($app)) {
    $params = $app->request()->params();

    $url = '';

    if(array_key_exists('url', $params))
      $url = $params['url'];

    $html = render('new-favorite', array(
      'title' => 'New Favorite',
      'url' => $url,
      'token' => generate_login_token()
    ));
    $app->response()->body($html);
  }
});

$app->post('/prefs', function() use($app) {
  if($user=require_login($app)) {
    $params = $app->request()->params();
    $user->location_enabled = $params['enabled'];
    $user->save();
  }
  $app->response()->body(json_encode(array(
    'result' => 'ok'
  )));
});

$app->get('/creating-a-token-endpoint', function() use($app) {
  $app->redirect('http://indiewebcamp.com/token-endpoint', 301);
});
$app->get('/creating-a-micropub-endpoint', function() use($app) {
  $html = render('creating-a-micropub-endpoint', array('title' => 'Creating a Micropub Endpoint'));
  $app->response()->body($html);
});

$app->get('/docs', function() use($app) {
  $html = render('docs', array('title' => 'Documentation'));
  $app->response()->body($html);
});

$app->get('/add-to-home', function() use($app) {
  $params = $app->request()->params();

  if(array_key_exists('token', $params) && !session('add-to-home-started')) {

    // Verify the token and sign the user in
    try {
      $data = JWT::decode($params['token'], Config::$jwtSecret);
      $_SESSION['user_id'] = $data->user_id;
      $_SESSION['me'] = $data->me;
      $app->redirect('/new', 301);
    } catch(DomainException $e) {
      header('X-Error: DomainException');
      $app->redirect('/', 301);
    } catch(UnexpectedValueException $e) {
      header('X-Error: UnexpectedValueException');
      $app->redirect('/', 301);
    }

  } else {

    if($user=require_login($app)) {
      if(array_key_exists('start', $params)) {
        $_SESSION['add-to-home-started'] = true;
        
        $token = JWT::encode(array(
          'user_id' => $_SESSION['user_id'],
          'me' => $_SESSION['me'],
          'created_at' => time()
        ), Config::$jwtSecret);

        $app->redirect('/add-to-home?token='.$token, 301);
      } else {
        unset($_SESSION['add-to-home-started']);
        $html = render('add-to-home', array('title' => 'Quill'));
        $app->response()->body($html);
      }
    }
  }
});

$app->get('/settings', function() use($app) {
  if($user=require_login($app)) {
    $html = render('settings', array('title' => 'Settings', 'include_facebook' => true));
    $app->response()->body($html);
  }
});

$app->get('/favorite-popup', function() use($app) {
  if($user=require_login($app)) {
    $params = $app->request()->params();

    $html = $app->render('favorite-popup.php', array(
      'url' => $params['url'], 
      'token' => $params['token']
    ));
    $app->response()->body($html);
  }
});

function create_favorite(&$user, $url) {
  $micropub_request = array(
    'like-of' => $url
  );
  $r = micropub_post_for_user($user, $micropub_request);

  $facebook_id = false;
  $instagram_id = false;
  $tweet_id = false;

  /*
  // Facebook likes are posted via Javascript, so pass the FB ID to the javascript code
  if(preg_match('/https?:\/\/(?:www\.)?facebook\.com\/(?:[^\/]+)\/posts\/(\d+)/', $url, $match)) {
    $facebook_id = $match[1];
  }

  if(preg_match('/https?:\/\/(?:www\.)?facebook\.com\/photo\.php\?fbid=(\d+)/', $url, $match)) {
    $facebook_id = $match[1];
  }
  */

  if(preg_match('/https?:\/\/(?:www\.)?instagram\.com\/p\/([^\/]+)/', $url, $match)) {
    $instagram_id = $match[1];
    if($user->instagram_access_token) {
      $instagram = instagram_client();
      $instagram->setAccessToken($user->instagram_access_token);
      $ch = curl_init('https://api.instagram.com/v1/media/shortcode/' . $instagram_id . '?access_token=' . $user->instagram_access_token);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $result = json_decode(curl_exec($ch));

      $result = $instagram->likeMedia($result->data->id);
    } else {
      // TODO: indicate that the instagram post couldn't be liked because no access token was available
    }
  }

  if(preg_match('/https?:\/\/(?:www\.)?twitter\.com\/[^\/]+\/status(?:es)?\/(\d+)/', $url, $match)) {
    $tweet_id = $match[1];
    $twitter = new \TwitterOAuth\Api(Config::$twitterClientID, Config::$twitterClientSecret, 
      $user->twitter_access_token, $user->twitter_token_secret);
    $result = $twitter->post('favorites/create', array(
      'id' => $tweet_id
    ));
  }

  return $r;
}

$app->get('/favorite.js', function() use($app) {
  $app->response()->header("Content-type", "text/javascript");
  if($user=require_login($app, false)) {
    $params = $app->request()->params();

    if(array_key_exists('url', $params)) {
      $r = create_favorite($user, $params['url']);

      $app->response()->body($app->render('favorite-js.php', array(
        'url' => $params['url'], 
        'like_url' => $r['location'], 
        'error' => $r['error'],
        // 'facebook_id' => $facebook_id
      )));
    } else {
      $app->response()->body('alert("no url");');
    }

  } else {
    $app->response()->body('alert("invalid token");');
  }
});

$app->get('/micropub/syndications', function() use($app) {
  if($user=require_login($app)) {
    $data = get_syndication_targets($user);
    $app->response()->body(json_encode(array(
      'targets' => $data['targets'],
      'response' => $data['response']
    )));
  }
});

$app->post('/micropub/post', function() use($app) {
  if($user=require_login($app)) {
    $params = $app->request()->params();

    // Remove any blank params
    $params = array_filter($params, function($v){
      return $v !== '';
    });

    $r = micropub_post_for_user($user, $params);

    $app->response()->body(json_encode(array(
      'request' => htmlspecialchars($r['request']),
      'response' => htmlspecialchars($r['response']),
      'location' => $r['location'],
      'error' => $r['error'],
      'curlinfo' => $r['curlinfo']
    )));
  }
});

/*
$app->post('/auth/facebook', function() use($app) {
  if($user=require_login($app, false)) {
    $params = $app->request()->params();
    // User just auth'd with facebook, store the access token
    $user->facebook_access_token = $params['fb_token'];
    $user->save();

    $app->response()->body(json_encode(array(
      'result' => 'ok'
    )));
  } else {
    $app->response()->body(json_encode(array(
      'result' => 'error'
    )));
  }
});
*/

$app->post('/auth/twitter', function() use($app) {
  if($user=require_login($app, false)) {
    $params = $app->request()->params();
    // User just auth'd with twitter, store the access token
    $user->twitter_access_token = $params['twitter_token'];
    $user->twitter_token_secret = $params['twitter_secret'];
    $user->save();

    $app->response()->body(json_encode(array(
      'result' => 'ok'
    )));
  } else {
    $app->response()->body(json_encode(array(
      'result' => 'error'
    )));
  }
});

function getTwitterLoginURL(&$twitter) {
  $request_token = $twitter->getRequestToken(Config::$base_url . 'auth/twitter/callback');
  $_SESSION['twitter_auth'] = $request_token;
  return $twitter->getAuthorizeURL($request_token['oauth_token']);
}

$app->get('/auth/twitter', function() use($app) {
  $params = $app->request()->params();
  if($user=require_login($app, false)) {

    // If there is an existing Twitter token, check if it is valid
    // Otherwise, generate a Twitter login link
    $twitter_login_url = false;
    $twitter = new \TwitterOAuth\Api(Config::$twitterClientID, Config::$twitterClientSecret, 
      $user->twitter_access_token, $user->twitter_token_secret);

    if(array_key_exists('login', $params)) {
      $twitter = new \TwitterOAuth\Api(Config::$twitterClientID, Config::$twitterClientSecret);
      $twitter_login_url = getTwitterLoginURL($twitter);
    } else {
      if($user->twitter_access_token) {
        if ($twitter->get('account/verify_credentials')) {
          $app->response()->body(json_encode(array(
            'result' => 'ok'
          )));
          return;
        } else {
          // If the existing twitter token is not valid, generate a login link
          $twitter_login_url = getTwitterLoginURL($twitter);
        }
      } else {
        $twitter_login_url = getTwitterLoginURL($twitter);
      }
    }

    $app->response()->body(json_encode(array(
      'url' => $twitter_login_url
    )));

  } else {
    $app->response()->body(json_encode(array(
      'result' => 'error'
    )));
  }
});

$app->get('/auth/twitter/callback', function() use($app) {
  if($user=require_login($app)) {
    $params = $app->request()->params();

    $twitter = new \TwitterOAuth\Api(Config::$twitterClientID, Config::$twitterClientSecret, 
      $_SESSION['twitter_auth']['oauth_token'], $_SESSION['twitter_auth']['oauth_token_secret']);
    $credentials = $twitter->getAccessToken($params['oauth_verifier']);

    $user->twitter_access_token = $credentials['oauth_token'];
    $user->twitter_token_secret = $credentials['oauth_token_secret'];
    $user->twitter_username = $credentials['screen_name'];
    $user->save();

    $app->redirect('/settings');
  }
});

$app->get('/auth/instagram', function() use($app) {
  if($user=require_login($app, false)) {

    $instagram = instagram_client();

    // If there is an existing Instagram auth token, check if it's valid
    if($user->instagram_access_token) {
      $instagram->setAccessToken($user->instagram_access_token);
      $igUser = $instagram->getUser();

      if($igUser && $igUser->meta->code == 200) {
        $app->response()->body(json_encode(array(
          'result' => 'ok',
          'username' => $igUser->data->username,
          'url' => $instagram->getLoginUrl(array('basic','likes'))
        )));
        return;
      }
    }

    $app->response()->body(json_encode(array(
      'result' => 'error',
      'url' => $instagram->getLoginUrl(array('basic','likes'))
    )));
  }
});

$app->get('/auth/instagram/callback', function() use($app) {
  if($user=require_login($app)) {
    $params = $app->request()->params();

    $instagram = instagram_client();
    $data = $instagram->getOAuthToken($params['code']);
    $user->instagram_access_token = $data->access_token;
    $user->save();

    $app->redirect('/settings');
  }
});

