<?php

/*
 * FileSender www.filesender.org
 * 
 * Copyright (c) 2009-2012, AARNet, Belnet, HEAnet, SURFnet, UNINETT
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 * 
 * *    Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 * *    Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in the
 *     documentation and/or other materials provided with the distribution.
 * *    Neither the name of AARNet, Belnet, HEAnet, SURFnet and UNINETT nor the
 *     names of its contributors may be used to endorse or promote products
 *     derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

// Require environment (fatal)
if(!defined('FILESENDER_BASE')) die('Missing environment');

/**
 * Represents a recipient in database
 * 
 * @property array $transfer related transfer
 */
class Recipient extends DBObject {
    /**
     * Database map
     */
    protected static $dataMap = array(
        'id' => array(
            'type' => 'uint',
            'size' => 'medium',
            'primary' => true,
            'autoinc' => true
        ),
        'transfer_id' => array(
            'type' => 'uint',
            'size' => 'medium',
        ),
        'email' => array(
            'type' => 'string',
            'size' => 255
        ),
        'token' => array(
            'type' => 'string',
            'size' => 60
        ),
        'created' => array(
            'type' => 'datetime'
        ),
        'last_activity' => array(
            'type' => 'datetime',
            'null' => true
        ),
        'options' => array(
            'type' => 'text',
            'transform' => 'json'
        )
    );
    
    /**
     * Properties
     */
    protected $id = null;
    protected $transfer_id = null;
    protected $email = '';
    protected $token = '';
    protected $created = 0;
    protected $last_activity = null;
    protected $options = null;
    
    /**
     * Related objects cache
     */
    private $transfer = null;
    
    /**
     * Constructor
     * 
     * @param integer $id identifier of recipient to load from database (null if loading not wanted)
     * @param array $data data to create the recipient from (if already fetched from database)
     * 
     * @throws RecipientNotFoundException
     */
    protected function __construct($id = null, $data = null) {
        if(!is_null($id)) {
            $statement = DBI::prepare('SELECT * FROM '.self::getDBTable().' WHERE id = :id');
            $statement->execute(array(':id' => $id));
            $data = $statement->fetch();
            if(!$data) throw new RecipientNotFoundException('id = '.$id);
        }
        
        if($data) $this->fillFromDBData($data);
    }
    
    /***
     * Loads recipient from token
     * 
     * @param string $token the token
     * 
     * @throws RecipientNotFoundException
     * 
     * @return object recipient
     */
    public static function fromToken($token) {
        $statement = DBI::prepare('SELECT * FROM '.self::getDBTable().' WHERE token = :token');
        $statement->execute(array(':token' => $token));
        $data = $statement->fetch();
        if(!$data) throw new RecipientNotFoundException('token = '.$token);
        
        $recipient = self::fromData($data['id'], $data);
        
        return $recipient;
    }
    
    /**
     * Create a new recipient bound to a transfer
     * 
     * @param object $transfer the relater transfer
     * @param object $email the recipient email
     * 
     * @return object file
     */
    public static function create($transfer, $email) {
        $recipient = new self();
        
        $recipient->transfer_id = $transfer->id;
        
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new BadEmailException($email);
        $recipient->email = $email;
        
        $recipient->created = time();
        
        // Generate token until it is indeed unique
        $recipient->token = Utilities::generateUID(function($token) {
            $statement = DBI::prepare('SELECT * FROM '.Recipient::getDBTable().' WHERE token = :token');
            $statement->execute(array(':token' => $token));
            $data = $statement->fetch();
            return !$data;
        });
        
        return $recipient;
    }
    
    /**
     * Save recipient in database
     */
    public function save() {
        if($this->id) {
            $this->updateRecord($this->toDBData(), 'id');
        }else{
            $this->insertRecord($this->toDBData());
            $this->id = (int)DBI::lastInsertId();
        }
    }
    
    /**
     * Delete the recipient
     */
    public function delete() {
        $s = DBI::prepare('DELETE FROM '.self::getDBTable().' WHERE id = :id');
        $s->execute(array('id' => $this->id));
    }
    
    /**
     * Get recipients from Transfer
     * 
     * @param object $transfer the relater transfer
     * 
     * @return array recipient list
     */
    public static function fromTransfer($transfer) {
        $s = DBI::prepare('SELECT * FROM '.self::getDBTable().' WHERE transfer_id = :transfer_id');
        $s->execute(array('transfer_id' => $transfer->id));
        $recipients = array();
        foreach($s->fetchAll() as $data) $recipients[$data['id']] = self::fromData($data['id'], $data); // Don't query twice, use loaded data
        return $recipients;
    }
    
    /**
     * Report last activity
     */
    public function reportActivity() {
        $this->last_activity = time();
        $this->save();
    }
    
    /**
     * Getter
     * 
     * @param string $property property to get
     * 
     * @throws PropertyAccessException
     * 
     * @return property value
     */
    public function __get($property) {
        if(in_array($property, array('id', 'transfer_id', 'email', 'token', 'created', 'last_activity', 'options'))) return $this->$property;
        
        if($property == 'transfer') {
            if(is_null($this->transfer)) $this->transfer = Transfer::fromId($this->transfer_id);
            return $this->transfer;
        }
        
        throw new PropertyAccessException($this, $property);
    }
    
    /**
     * Setter
     * 
     * @param string $property property to get
     * @param mixed $value value to set property to
     * 
     * @throws PropertyAccessException
     */
    public function __set($property, $value) {
        if($property == 'options') {
            $this->options = $value;
        }else throw new PropertyAccessException($this, $property);
    }
}