<?php

use Infinex\Exceptions\Error;

class AnnouncementsAPI {
    private $log;
    private $anno;
    
    function __construct($log, $anno) {
        $this -> log = $log;
        $this -> anno = $anno;
        
        $this -> log -> debug('Initialized announcements API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/', [$this, 'getAnnouncements']);
        $rc -> get('/{path}', [$this, 'getAnnouncement']);
    }
    
    public function getAnnouncements($path, $query, $body, $auth) {
        $resp = $this -> anno -> getAnnouncements([
            'enabled' => true,
            'offset' => @$query['offset'],
            'limit' => @$queryp['limit']
        ]);
        
        foreach($resp['announcements'] as $k => $v)
            $resp['announcements'][$k] = $this -> ptpAnnouncement($v, false);
        
        return $resp;
    }
    
    public function getAnnouncement($path, $query, $body, $auth) {
        $anno = $this -> anno -> getAnnouncement([
            'path' => $path['path']
        ]);
        
        if(!$anno['enabled'])
            throw new Error('FORBIDDEN', 'No permissions to announcement '.$path['path']);
        
        return $this -> ptpAnnouncement($anno, isset($query['full']));
    }
    
    private function ptpAnnouncement($record, $full) {
        $resp = [
            'time' => $record['time'],
            'path' => $record['path'],
            'title' => $record['title'],
            'excerpt' => $record['excerpt'],
            'featureImg' => $record['featureImg']
        ];
        
        if($full)
            $resp['body'] = $record['body'];
        
        return $resp;
    }
}

?>