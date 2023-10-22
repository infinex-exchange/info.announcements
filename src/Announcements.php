<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Search;
use function Infinex\Validation\validateId;
use React\Promise;

class Announcements {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized announcements manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getAnnouncements',
            [$this, 'getAnnouncements']
        );
        
        $promises[] = $this -> amqp -> method(
            'getAnnouncement',
            [$this, 'getAnnouncement']
        );
        
        $promises[] = $this -> amqp -> method(
            'deleteAnnouncement',
            [$this, 'deleteAnnouncement']
        );
        
        $promises[] = $this -> amqp -> method(
            'editAnnouncement',
            [$this, 'editAnnouncement']
        );
        
        $promises[] = $this -> amqp -> method(
            'createAnnouncement',
            [$this, 'createAnnouncement']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started announcements manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start announcements manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getAnnouncements');
        $promises[] = $this -> amqp -> unreg('getAnnouncement');
        $promises[] = $this -> amqp -> unreg('deleteAnnouncement');
        $promises[] = $this -> amqp -> unreg('editAnnouncement');
        $promises[] = $this -> amqp -> unreg('createAnnouncement');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped announcement manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop announcement manager: '.((string) $e));
            }
        );
    }
    
    public function getAnnouncements($body) {
        if(isset($body['enabled']) && !is_bool($body['enabled']))
            throw new Error('VALIDATION_ERROR', 'enabled');
            
        $pag = new Pagination\Offset(50, 500, $body);
        $search = new Search(
            [
                'path',
                'title',
                'excerpt',
                'body'
            ],
            $body
        );
            
        $task = [];
        $search -> updateTask($task);
        
        $sql = 'SELECT annoid,
                       EXTRACT(epoch FROM time) AS time,
                       path,
                       title,
                       excerpt,
                       feature_img,
                       body,
                       enabled
                FROM announcements
                WHERE 1=1';
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ' AND enabled = :enabled';
        }
            
        $sql .= $search -> sql()
             .' ORDER BY time DESC'
             . $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $announcements = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $announcements[] = $this -> rtrAnnouncement($row);
        }
            
        return [
            'announcements' => $announcements,
            'more' => $pag -> more
        ];
    }
    
    public function getAnnouncement($body) {
        if(!isset($body['annoid']))
            throw new Error('MISSING_DATA', 'annoid', 400);
        
        if(!validateId($body['annoid']))
            throw new Error('VALIDATION_ERROR', 'annoid', 400);
        
        $task = [
            ':annoid' => $body['annoid']
        ];
        
        $sql = 'SELECT annoid,
                       EXTRACT(epoch FROM time) AS time,
                       path,
                       title,
                       excerpt,
                       feature_img,
                       body,
                       enabled
                FROM announcements
                WHERE annoid = :annoid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Announcement '.$body['annoid'].' not found', 404);
            
        return $this -> rtrAnnouncement($row);
    }
    
    public function deleteAnnouncement($body) {
        if(!isset($body['annoid']))
            throw new Error('MISSING_DATA', 'annoid');
        
        if(!validateId($body['annoid']))
            throw new Error('VALIDATION_ERROR', 'annoid');
        
        $task = [
            ':annoid' => $body['annoid']
        ];
        
        $sql = 'DELETE FROM announcements
                WHERE annoid = :annoid
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Announcement '.$body['annoid'].' not found');
    }
    
    public function editAnnouncement($body) {
        if(!isset($body['annoid']))
            throw new Error('MISSING_DATA', 'annoid');
        
        if(!validateId($body['annoid']))
            throw new Error('VALIDATION_ERROR', 'annoid');
        
        if(
            !isset($body['time']) &&
            !isset($body['path']) &&
            !isset($body['title']) &&
            !isset($body['excerpt']) &&
            !array_key_exists('featuredImg', $body) &&
            !array_key_exists('body', $body) &&
            !isset($body['enabled'])
        )
            throw new Error('MISSING_DATA', 'Nothing to change');
        
        if(isset($body['time']) && !is_int($body['time']))
            throw new Error('VALIDATION_ERROR', 'time');
        if(isset($body['path']) && !$this -> validatePath($body['path']))
            throw new Error('VALIDATION_ERROR', 'path');
        if(isset($body['title']) && (!is_string($body['title']) || strlen($body['title']) > 255))
            throw new Error('VALIDATION_ERROR', 'title');
        if(isset($body['excerpt']) && !is_string($body['excerpt']))
            throw new Error('VALIDATION_ERROR', 'except');
        if(isset($body['featuredImg']) && (!is_string($body['featuredImg']) || strlen($body['featuredImg']) > 255))
            throw new Error('VALIDATION_ERROR', 'featuredImg');
        if(isset($body['body']) && !is_string($body['body']))
            throw new Error('VALIDATION_ERROR', 'body');
        if(isset($body['enabled']) && !is_bool($body['enabled']))
            throw new Error('VALIDATION_ERROR', 'enabled');
        
        $task = [
            ':annoid' => $body['annoid']
        ];
        
        $sql = 'UPDATE announcements
                SET annoid = annoid';
        
        if(isset($body['time'])) {
            $task[':time'] = $body['time'];
            $sql .= ', time = TO_TIMESTAMP(:time)';
        }
        
        if(isset($body['path'])) {
            $task[':path'] = $body['path'];
            $sql .= ', path = :path';
        }
        
        if(isset($body['title'])) {
            $task[':title'] = $body['title'];
            $sql .= ', title = :title';
        }
        
        if(isset($body['excerpt'])) {
            $task[':excerpt'] = $body['excerpt'];
            $sql .= ', excerpt = :excerpt';
        }
        
        if(array_key_exists('featuredImg', $body)) {
            $task[':feature_img'] = $body['featuredImg'];
            $sql .= ', feature_img = :feature_img';
        }
        
        if(array_key_exists('body', $body)) {
            $task[':body'] = $body['body'];
            $sql .= ', body = :body';
        }
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ', enabled = :enabled';
        }
        
        $sql .= ' WHERE annoid = :annoid
                  RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Announcement '.$body['annoid'].' not found');
    }
    
    public function createAnnouncement($body) {
        if(!isset($body['path']))
            throw new Error('MISSING_DATA', 'path');
        if(!isset($body['title']))
            throw new Error('MISSING_DATA', 'title');
        if(!isset($body['excerpt']))
            throw new Error('MISSING_DATA', 'excerpt');
        
        if(!$this -> validatePath($body['path']))
            throw new Error('VALIDATION_ERROR', 'path');
        if(!is_string($body['title']) || strlen($body['title']) > 255)
            throw new Error('VALIDATION_ERROR', 'title');
        if(!is_string($body['excerpt']))
            throw new Error('VALIDATION_ERROR', 'excerpt');
        
        if(isset($body['time']) && !is_int($body['time']))
            throw new Error('VALIDATION_ERROR', 'time');
        if(isset($body['featuredImg']) && (!is_string($body['featuredImg']) || strlen($body['featuredImg']) > 255))
            throw new Error('VALIDATION_ERROR', 'featuredImg');
        if(isset($body['body']) && !is_string($body['body']))
            throw new Error('VALIDATION_ERROR', 'body');
        if(isset($body['enabled']) && !is_bool($body['enabled']))
            throw new Error('VALIDATION_ERROR', 'enabled');
        
        $task = array(
            ':path' => $body['path'],
            ':title' => $body['title'],
            ':excerpt' => $body['excerpt'],
            ':feature_img' => @$body['featuredImg'],
            ':body' => @$body['body'],
            ':enabled' => @$body['enabled'] ? 1 : 0,
        );
        
        if(isset($body['time'])) {
            $task[':time'] = $body['time'];
            $sqlTime = 'TO_TIMESTAMP(:time)';
        }
        else
            $sqlTime = 'DEFAULT';
        
        $sql = "INSERT INTO announcements(
                    time,
                    path,
                    title,
                    excerpt,
                    feature_img,
                    body,
                    enabled
                ) VALUES (
                    $sqlTime,
                    :path,
                    :title,
                    :excerpt,
                    :feature_img,
                    :body,
                    :enabled
                )
                RETURNING annoid";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        return [
            'annoid' => $row['annoid']
        ];
    }
    
    private function rtrAnnouncement($row) {
        return [
            'annoid' => $row['annoid'],
            'time' => intval($row['time']),
            'path' => $row['path'],
            'title' => $row['title'],
            'excerpt' => $row['excerpt'],
            'featuredImg' => $row['feature_img'],
            'body' => $row['body'],
            'enabled' => $row['enabled']
        ];
    }
    
    private function validatePath($path) {
        return preg_match('/^[a-z0-9\-]{1,255}$/', $path);
    }
}

?>