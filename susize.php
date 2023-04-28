<?php
define('BOT_TOKEN', '5758245:AAE2QSbzNu9JZ2xsKfJ9pxplzQDwLByuTMQ');
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');

function apiRequestWebhook($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  header("Content-Type: application/json");
  echo json_encode($parameters);
  return true;
}

function exec_curl_request($handle) {
  $response = curl_exec($handle);

  if ($response === false) {
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    error_log("Curl returned error $errno: $error\n");
    curl_close($handle);
    return false;
  }

  $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
  curl_close($handle);

  if ($http_code >= 500) {
    sleep(10);
    return false;
  } else if ($http_code != 200) {
    $response = json_decode($response, true);
    error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
    if ($http_code == 401) {
      throw new Exception('Invalid access token provided');
    }
    return false;
  } else {
    $response = json_decode($response, true);
    if (isset($response['description'])) {
      error_log("Request was successful: {$response['description']}\n");
    }
    $response = $response['result'];
  }

  return $response;
}

function apiRequest($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  foreach ($parameters as $key => &$val) {
    if (!is_numeric($val) && !is_string($val)) {
      $val = json_encode($val);
    }
  }
  $url = API_URL.$method.'?'.http_build_query($parameters);

  $handle = curl_init($url);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);

  return exec_curl_request($handle);
}

function apiRequestJson($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  $handle = curl_init(API_URL);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);
  curl_setopt($handle, CURLOPT_POST, true);
  curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
  curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

  return exec_curl_request($handle);
}

function processMessage($message) {
  if (isset($message['message'])) {
    $message = $message['message'];
  } else if (isset($message['edited_message'])) {
    $message = $message['edited_message'];
  } else if (isset($message['channel_post'])) {
    $message = $message['channel_post'];
  } else if (isset($message['edited_channel_post'])) {
    $message = $message['edited_channel_post'];
  } else {
    return;
  }

  if (isset($message['text'])) {
    $chat_id = $message['chat']['id'];
    $text = $message['text'];

    if ($text === '/start') {
      $user_id = $message['from']['id'];
      $file_name = 'size_' . $user_id . '.json';
      if (!file_exists($file_name)) {
        $size = rand(1, 30);
        file_put_contents($file_name, json_encode(['size' => $size]));
        apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'Your size is ' . $size));
        apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'You can now use the inline query mode', 'reply_markup' => array(
          'inline_keyboard' => [[array('text' => 'Try inline query', 'switch_inline_query' => '')]]
        )));
      } else {
        $data = json_decode(file_get_contents($file_name), true);
        apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'Your size is ' . $data['size']));
      }
    } else if ($text === '/install') {
      apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'The bot has been installed in this group. Use /size to get your size.'));
    } else if ($text === '/size') {
      if ($message['chat']['type'] === 'group' || $message['chat']['type'] === 'supergroup') {
        if (isset($message['reply_to_message'])) {
          $user_id = $message['reply_to_message']['from']['id'];
          $file_name = 'size_' . $user_id . '.json';
          if (file_exists($file_name)) {
            $data = json_decode(file_get_contents($file_name), true);
            apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'Your size is ' . $data['size'], 'reply_to_message_id' => $message['reply_to_message']['message_id']));
          } else {
            apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'You need to start the bot first to get your size.', 'reply_to_message_id' => $message['reply_to_message']['message_id']));
          }
        } else {
          apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'Please reply to a message to get the size of that user.'));
        }
      }
    }
  }
}

function processInlineQuery($inline_query) {
  global $_cached_inline_results;

  if (!isset($_cached_inline_results)) {
    $_cached_inline_results = array();
  }

  if (isset($_cached_inline_results[$inline_query['id']])) {
    return;
  }

  $_cached_inline_results[$inline_query['id']] = true;

  if (isset($inline_query['query'])) {
    $user_id = $inline_query['from']['id'];
    $file_name = 'size_' . $user_id . '.json';
    if (file_exists($file_name)) {
      $data = json_decode(file_get_contents($file_name), true);
      apiRequest("answerInlineQuery", array('inline_query_id' => $inline_query['id'], "results" => json_encode(array(
        array(
          "type" => "article",
          "id" => "1",
          "title" => "Your size",
          "input_message_content" => array("message_text" => "My dick size is " . $data['size']),
        )
      ))));
    } else {
      apiRequest("answerInlineQuery", array('inline_query_id' => $inline_query['id'], "results" => json_encode(array(
        array(
          "type" => "article",
          "id" => "1",
          "title" => "Start the bot first",
          "input_message_content" => array("message_text" => "You need to start the bot first to get your size."),
        )
      ))));
    }
  }
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
  exit;
}

if (isset($update["message"])) {
  processMessage($update);
} else if (isset($update["inline_query"])) {
  processInlineQuery($update["inline_query"]);
}