<?php

/*
 * LimeSurvey
 * Copyright (C) 2007-2011 The LimeSurvey Project Team / Carsten Schmitz
 * All rights reserved.
 * License: GNU/GPL License v2 or later, see LICENSE.php
 * LimeSurvey is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 *
 */

/**
 * Class FailedLoginAttempt
 *
 * @property integer $id
 * @property string $ip Ip address
 * @property string $last_attempt
 * @property integer $number_attempts
 * @property int $is_frontend  from frontend(=1) or from backend (=0)
 */
class FailedLoginAttempt extends LSActiveRecord
{
    public const TYPE_LOGIN = 'login';
    public const TYPE_TOKEN = 'token';

    /**
     * @inheritdoc
     * @return FailedLoginAttempt
     */
    public static function model($className = __CLASS__)
    {
        /** @var self $model */
        $model = parent::model($className);
        return $model;
    }

    /** @inheritdoc */
    public function primaryKey()
    {
        return 'id';
    }

    /** @inheritdoc */
    public function tableName()
    {
        return '{{failed_login_attempts}}';
    }

    /**
     * Deletes all the attempts by IP.
     * Separation between backend and frontend
     *
     * @param  string $attemptType  The attempt type ('login' or 'token').
     *
     * @access public
     * @return void
     */
    public function deleteAttempts(string $attemptType = 'login')
    {
        $ip = substr(getIPAddress(), 0, 40);

        $this->deleteAllByAttributes(array('ip' => $ip, 'is_frontend' => ($attemptType === FailedLoginAttempt::TYPE_TOKEN)));
    }

    /**
     * Check if an IP address is allowed to log in or not
     *
     * @param string $attemptType  The attempt type ('login' or 'token')
     *
     * @return bool Returns true if the user is blocked
     */
    public function isLockedOut(string $attemptType): bool
    {
        $isLockedOut = false;
        $ip = substr(getIPAddress(), 0, 40);

        // Return false if IP is whitelisted
        if ($this->isWhitelisted($ip, $attemptType)) {
            return false;
        }

        switch ($attemptType) {
            case FailedLoginAttempt::TYPE_LOGIN:
                $timeOut = Yii::app()->getConfig('timeOutTime');
                $maxLoginAttempt = Yii::app()->getConfig('maxLoginAttempt');
                break;
            case FailedLoginAttempt::TYPE_TOKEN:
                $timeOut = Yii::app()->getConfig('timeOutParticipants');
                $maxLoginAttempt = Yii::app()->getConfig('maxLoginAttemptParticipants');
                break;
            default:
                throw new InvalidArgumentException(sprintf("Invalid attempt type: %s", $attemptType));
        }

        $criteria = new CDbCriteria();
        $criteria->condition = 'number_attempts > :attempts AND ip = :ip AND is_frontend = :is_frontend';
        $criteria->params = array(
            ':attempts' => $maxLoginAttempt,
            ':ip' => $ip,
            ':is_frontend' => ($attemptType === FailedLoginAttempt::TYPE_TOKEN)
        );

        $row = $this->find($criteria);

        if ($row != null) {
            $lastattempt = strtotime($row->last_attempt);
            if (time() > $lastattempt + $timeOut) {
                $this->deleteAttempts($attemptType);
            } else {
                $isLockedOut = true;
            }
        }
        return $isLockedOut;
    }

    /**
     * Records a failed login-attempt if IP is not already locked out
     *
     * @param string attempt type ('login' or 'token')
     *
     * @access public
     * @return void
     */
    public function addAttempt($attemptType = self::TYPE_LOGIN)
    {
        if (!$this->isLockedOut($attemptType)) {
            $timestamp = date("Y-m-d H:i:s");
            $ip = substr(getIPAddress(), 0, 40);
            $row = $this->findByAttributes(array('ip' => $ip, 'is_frontend' => ($attemptType === self::TYPE_TOKEN)));

            if ($row !== null) {
                $row->number_attempts = $row->number_attempts + 1;
                $row->last_attempt = $timestamp;
                $row->save();
            } else {
                $record = new FailedLoginAttempt();
                $record->ip = $ip;
                $record->number_attempts = 1;
                $record->last_attempt = $timestamp;
                $record->is_frontend = ($attemptType === self::TYPE_TOKEN);
                $record->save();
            }
        }
    }

    /**
     * Returns true if the specified IP is whitelisted
     *
     * @param string $ip
     * @param string $attemptType   'login' or 'token'
     *
     * @throws InvalidArgumentException if an invalid attempt type is specified
     * @return boolean
     */
    private function isWhitelisted(string $ip, string $attemptType): bool
    {
        // Init
        if ($attemptType != self::TYPE_LOGIN && $attemptType != self::TYPE_TOKEN) {
            throw new InvalidArgumentException(sprintf("Invalid attempt type: %s", $attemptType));
        }
        if (empty($ip)) {
            return false;
        }
        $binaryIP = inet_pton($ip);

        $whiteList = Yii::app()->getConfig($attemptType . 'IpWhitelist');
        if (empty($whiteList)) {
            return false;
        }

        // Validating
        $whiteListEntries = preg_split('/\n|,/', $whiteList);
        foreach ($whiteListEntries as $whiteListEntry) {
            if (empty($whiteListEntry)) {
                continue;
            }
            // Compare directly
            if ($whiteListEntry == $ip) {
                // The IP is whitelisted
                return true;
            }
            // Compare binary representations
            $binaryWhiteListEntry = inet_pton($whiteListEntry);
            if ($binaryWhiteListEntry !== false && $binaryWhiteListEntry == $binaryIP) {
                // The IP is whitelisted
                return true;
            }
        }

        // Not whitelisted
        return false;
    }
}
