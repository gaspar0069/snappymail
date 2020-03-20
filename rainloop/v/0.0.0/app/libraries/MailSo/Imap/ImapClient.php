<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Imap;

/**
 * @category MailSo
 * @package Imap
 */
class ImapClient extends \MailSo\Net\NetClient
{
	/**
	 * @var string
	 */
	const TAG_PREFIX = 'TAG';

	/**
	 * @var int
	 */
	private $iResponseBufParsedPos;

	/**
	 * @var int
	 */
	private $iTagCount;

	/**
	 * @var array
	 */
	private $aCapabilityItems;

	/**
	 * @var \MailSo\Imap\FolderInformation
	 */
	private $oCurrentFolderInfo;

	/**
	 * @var array
	 */
	private $aLastResponse;

	/**
	 * @var array
	 */
	private $aFetchCallbacks;

	/**
	 * @var bool
	 */
	private $bNeedNext;

	/**
	 * @var array
	 */
	private $aPartialResponses;

	/**
	 * @var array
	 */
	private $aTagTimeouts;

	/**
	 * @var bool
	 */
	private $bIsLoggined;

	/**
	 * @var bool
	 */
	private $bIsSelected;

	/**
	 * @var string
	 */
	private $sLogginedUser;

	/**
	 * @var bool
	 */
	public $__FORCE_SELECT_ON_EXAMINE__;

	protected function __construct()
	{
		parent::__construct();

		$this->iTagCount = 0;
		$this->aCapabilityItems = null;
		$this->oCurrentFolderInfo = null;
		$this->aFetchCallbacks = null;
		$this->iResponseBufParsedPos = 0;

		$this->aLastResponse = array();
		$this->bNeedNext = true;
		$this->aPartialResponses = array();

		$this->aTagTimeouts = array();

		$this->bIsLoggined = false;
		$this->bIsSelected = false;
		$this->sLogginedUser = '';

		$this->__FORCE_SELECT_ON_EXAMINE__ = false;

		@\ini_set('xdebug.max_nesting_level', 500);
	}

	public static function NewInstance() : self
	{
		return new self();
	}

	public function GetLogginedUser() : string
	{
		return $this->sLogginedUser;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function Connect(string $sServerName, int $iPort = 143,
		int $iSecurityType = \MailSo\Net\Enumerations\ConnectionSecurityType::AUTO_DETECT,
		bool $bVerifySsl = false, bool $bAllowSelfSigned = true,
		string $sClientCert = '') : void
	{
		$this->aTagTimeouts['*'] = \microtime(true);

		parent::Connect($sServerName, $iPort, $iSecurityType, $bVerifySsl, $bAllowSelfSigned, $sClientCert);

		$this->parseResponseWithValidation('*', true);

		if (\MailSo\Net\Enumerations\ConnectionSecurityType::UseStartTLS(
			$this->IsSupported('STARTTLS'), $this->iSecurityType))
		{
			$this->SendRequestWithCheck('STARTTLS');
			$this->EnableCrypto();

			$this->aCapabilityItems = null;
		}
		else if (\MailSo\Net\Enumerations\ConnectionSecurityType::STARTTLS === $this->iSecurityType)
		{
			$this->writeLogException(
				new \MailSo\Net\Exceptions\SocketUnsuppoterdSecureConnectionException('STARTTLS is not supported'),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}
	}

	protected function _xor($string, $string2)
    {
        $result = '';
        $size   = strlen($string);
        for ($i=0; $i<$size; $i++) {
            $result .= chr(ord($string[$i]) ^ ord($string2[$i]));
        }
        return $result;
    }

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function Login(string $sLogin, string $sPassword, string $sProxyAuthUser = '',
		bool $bUseAuthPlainIfSupported = true, bool $bUseAuthCramMd5IfSupported = true) : self
	{
		if (!strlen(\trim($sLogin)) || !strlen(\trim($sPassword)))
		{
			$this->writeLogException(
				new \MailSo\Base\Exceptions\InvalidArgumentException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		$sLogin = \MailSo\Base\Utils::IdnToAscii(\MailSo\Base\Utils::Trim($sLogin));

		$sPassword = $sPassword;

		$this->sLogginedUser = $sLogin;

		try
		{
			if ($bUseAuthCramMd5IfSupported && $this->IsSupported('AUTH=CRAM-MD5'))
			{
				$this->SendRequest('AUTHENTICATE', array('CRAM-MD5'));

				$aResponse = $this->parseResponseWithValidation();
				if ($aResponse && \is_array($aResponse) && 0 < \count($aResponse) &&
					\MailSo\Imap\Enumerations\ResponseType::CONTINUATION === $aResponse[\count($aResponse) - 1]->ResponseType)
				{
					$oContinuationResponse = null;
					foreach ($aResponse as $oResponse)
					{
						if ($oResponse && \MailSo\Imap\Enumerations\ResponseType::CONTINUATION === $oResponse->ResponseType)
						{
							$oContinuationResponse = $oResponse;
						}
					}

					if ($oContinuationResponse && !empty($oContinuationResponse->ResponseList[1]))
					{
						$sTicket = @\base64_decode($oContinuationResponse->ResponseList[1]);
						$this->oLogger->Write('ticket: '.$sTicket);

						$sToken = \base64_encode($sLogin.' '.\hash_hmac('md5', $sTicket, $sPassword));

						if ($this->oLogger)
						{
							$this->oLogger->AddSecret($sToken);
						}

						$this->sendRaw($sToken, true, '*******');
						$this->parseResponseWithValidation();
					}
					else
					{
						$this->writeLogException(
							new \MailSo\Imap\Exceptions\LoginException(),
							\MailSo\Log\Enumerations\Type::NOTICE, true);
					}
				}
				else
				{
					$this->writeLogException(
						new \MailSo\Imap\Exceptions\LoginException(),
						\MailSo\Log\Enumerations\Type::NOTICE, true);
				}
			}
			else if ($bUseAuthPlainIfSupported && $this->IsSupported('AUTH=PLAIN'))
			{
				$sToken = \base64_encode("\0".$sLogin."\0".$sPassword);
				if ($this->oLogger)
				{
					$this->oLogger->AddSecret($sToken);
				}

				if ($this->IsSupported('AUTH=SASL-IR') && false)
				{
					$this->SendRequestWithCheck('AUTHENTICATE', array('PLAIN', $sToken));
				}
				else
				{
					$this->SendRequest('AUTHENTICATE', array('PLAIN'));
					$this->parseResponseWithValidation();

					$this->sendRaw($sToken, true, '*******');
					$this->parseResponseWithValidation();
				}
			}
			else
			{
				if ($this->oLogger)
				{
					$this->oLogger->AddSecret($this->EscapeString($sPassword));
				}

				$this->SendRequestWithCheck('LOGIN',
					array(
						$this->EscapeString($sLogin),
						$this->EscapeString($sPassword)
					));
			}
//			else
//			{
//				$this->writeLogException(
//					new \MailSo\Imap\Exceptions\LoginBadMethodException(),
//					\MailSo\Log\Enumerations\Type::NOTICE, true);
//			}

			if (0 < \strlen($sProxyAuthUser))
			{
				$this->SendRequestWithCheck('PROXYAUTH', array($this->EscapeString($sProxyAuthUser)));
			}
		}
		catch (\MailSo\Imap\Exceptions\NegativeResponseException $oException)
		{
			$this->writeLogException(
				new \MailSo\Imap\Exceptions\LoginBadCredentialsException(
					$oException->GetResponses(), '', 0, $oException),
				\MailSo\Log\Enumerations\Type::NOTICE, true);
		}

		$this->bIsLoggined = true;
		$this->aCapabilityItems = null;

		return $this;
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 */
	public function Logout() : self
	{
		if ($this->bIsLoggined)
		{
			$this->bIsLoggined = false;
			$this->SendRequestWithCheck('LOGOUT', array());
		}

		return $this;
	}

	public function ForceCloseConnection() : self
	{
		$this->Disconnect();

		return $this;
	}

	public function IsLoggined() : bool
	{
		return $this->IsConnected() && $this->bIsLoggined;
	}

	public function IsSelected() : bool
	{
		return $this->IsLoggined() && $this->bIsSelected;
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function Capability() : ?array
	{
		$this->SendRequestWithCheck('CAPABILITY', array(), true);
		return $this->aCapabilityItems;
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function IsSupported(string $sExtentionName) : bool
	{
		$bResult = strlen(\trim($sExtentionName));
		if ($bResult && null === $this->aCapabilityItems)
		{
			$this->aCapabilityItems = $this->Capability();
		}

		return $bResult && \is_array($this->aCapabilityItems) &&
			\in_array(\strtoupper($sExtentionName), $this->aCapabilityItems);
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function GetNamespace() : ?\MailSo\Imap\NamespaceResult
	{
		if (!$this->IsSupported('NAMESPACE'))
		{
			return null;
		}

		$oReturn = null;

		$this->SendRequest('NAMESPACE');
		$aResult = $this->parseResponseWithValidation();

		$oImapResponse = null;
		foreach ($aResult as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
		{
			if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType &&
				'NAMESPACE' === $oImapResponse->StatusOrIndex)
			{
				$oReturn = NamespaceResult::NewInstance();
				$oReturn->InitByImapResponse($oImapResponse);
				break;
			}
		}

		if (!$oReturn)
		{
			$this->writeLogException(
				new \MailSo\Imap\Exceptions\ResponseException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		return $oReturn;
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function Noop() : self
	{
		return $this->SendRequestWithCheck('NOOP');
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderCreate(string $sFolderName) : self
	{
		return $this->SendRequestWithCheck('CREATE',
			array($this->EscapeString($sFolderName)));
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderDelete(string $sFolderName) : self
	{
		return $this->SendRequestWithCheck('DELETE',
			array($this->EscapeString($sFolderName)));
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderSubscribe(string $sFolderName) : self
	{
		return $this->SendRequestWithCheck('SUBSCRIBE',
			array($this->EscapeString($sFolderName)));
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderUnSubscribe(string $sFolderName) : self
	{
		return $this->SendRequestWithCheck('UNSUBSCRIBE',
			array($this->EscapeString($sFolderName)));
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderRename(string $sOldFolderName, string $sNewFolderName) : self
	{
		return $this->SendRequestWithCheck('RENAME', array(
			$this->EscapeString($sOldFolderName),
			$this->EscapeString($sNewFolderName)));
	}

	protected function getStatusFolderInformation(array $aResult) : array
	{
		$aReturn = array();

		if (\is_array($aResult))
		{
			$oImapResponse = null;
			foreach ($aResult as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
			{
				if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType &&
					'STATUS' === $oImapResponse->StatusOrIndex && isset($oImapResponse->ResponseList[3]) &&
					\is_array($oImapResponse->ResponseList[3]))
				{
					$sName = null;
					foreach ($oImapResponse->ResponseList[3] as $sArrayItem)
					{
						if (null === $sName)
						{
							$sName = $sArrayItem;
						}
						else
						{
							$aReturn[$sName] = $sArrayItem;
							$sName = null;
						}
					}
				}
			}
		}

		return $aReturn;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderStatus(string $sFolderName, array $aStatusItems) : array
	{
		$aResult = false;
		if (\count($aStatusItems) > 0)
		{
			$this->SendRequest('STATUS',
				array($this->EscapeString($sFolderName), $aStatusItems));

			$aResult = $this->getStatusFolderInformation(
				$this->parseResponseWithValidation());
		}

		return $aResult;
	}

	private function getFoldersFromResult(array $aResult, string $sStatus, bool $bUseListStatus = false) : array
	{
		$aReturn = array();

		$sDelimiter = '';
		$bInbox = false;

		$oImapResponse = null;
		foreach ($aResult as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
		{
			if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType &&
				$sStatus === $oImapResponse->StatusOrIndex && 5 === count($oImapResponse->ResponseList))
			{
				try
				{
					$oFolder = Folder::NewInstance($oImapResponse->ResponseList[4],
						$oImapResponse->ResponseList[3], $oImapResponse->ResponseList[2]);

					if ($oFolder->IsInbox())
					{
						$bInbox = true;
					}

					if (empty($sDelimiter))
					{
						$sDelimiter = $oFolder->Delimiter();
					}

					$aReturn[] = $oFolder;
				}
				catch (\MailSo\Base\Exceptions\InvalidArgumentException $oException)
				{
					$this->writeLogException($oException, \MailSo\Log\Enumerations\Type::WARNING, false);
				}
			}
		}

		if (!$bInbox && !empty($sDelimiter))
		{
			$aReturn[] = Folder::NewInstance('INBOX', $sDelimiter);
		}

		if ($bUseListStatus)
		{
			foreach ($aResult as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
			{
				if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType &&
					'STATUS' === $oImapResponse->StatusOrIndex &&
					isset($oImapResponse->ResponseList[2]) &&
					isset($oImapResponse->ResponseList[3]) &&
					\is_array($oImapResponse->ResponseList[3]))
				{
					$sFolderNameRaw = $oImapResponse->ResponseList[2];

					$oCurrentFolder = null;
					foreach ($aReturn as $oFolder)
					{
						if ($oFolder && $sFolderNameRaw === $oFolder->FullNameRaw())
						{
							$oCurrentFolder =& $oFolder;
							break;
						}
					}

					if (null !== $oCurrentFolder)
					{
						$sName = null;
						$aStatus = array();

						foreach ($oImapResponse->ResponseList[3] as $sArrayItem)
						{
							if (null === $sName)
							{
								$sName = $sArrayItem;
							}
							else
							{
								$aStatus[$sName] = $sArrayItem;
								$sName = null;
							}
						}

						if (0 < count($aStatus))
						{
							$oCurrentFolder->SetExtended('STATUS', $aStatus);
						}
					}

					unset($oCurrentFolder);
				}
			}
		}

		return $aReturn;
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	private function specificFolderList(bool $bIsSubscribeList, string $sParentFolderName = '', string $sListPattern = '*', bool $bUseListStatus = false) : array
	{
		$sCmd = 'LSUB';
		if (!$bIsSubscribeList)
		{
			$sCmd = 'LIST';
		}

		$sListPattern = 0 === strlen(trim($sListPattern)) ? '*' : $sListPattern;

		$aParameters = array(
			$this->EscapeString($sParentFolderName),
			$this->EscapeString($sListPattern)
		);

		if ($bUseListStatus && !$bIsSubscribeList && $this->IsSupported('LIST-STATUS'))
		{
			$aL = array(
				\MailSo\Imap\Enumerations\FolderStatus::MESSAGES,
				\MailSo\Imap\Enumerations\FolderStatus::UNSEEN,
				\MailSo\Imap\Enumerations\FolderStatus::UIDNEXT
			);

//			if ($this->IsSupported('CONDSTORE'))
//			{
//				$aL[] = \MailSo\Imap\Enumerations\FolderStatus::HIGHESTMODSEQ;
//			}

			$aParameters[] = 'RETURN';
			$aParameters[] = array('STATUS', $aL);
		}
		else
		{
			$bUseListStatus = false;
		}

		$this->SendRequest($sCmd, $aParameters);

		return $this->getFoldersFromResult(
			$this->parseResponseWithValidation(), $sCmd, $bUseListStatus);
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderList(string $sParentFolderName = '', string $sListPattern = '*') : array
	{
		return $this->specificFolderList(false, $sParentFolderName, $sListPattern);
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderSubscribeList(string $sParentFolderName = '', string $sListPattern = '*') : array
	{
		return $this->specificFolderList(true, $sParentFolderName, $sListPattern);
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderStatusList(string $sParentFolderName = '', string $sListPattern = '*') : array
	{
		return $this->specificFolderList(false, $sParentFolderName, $sListPattern, true);
	}

	protected function initCurrentFolderInformation(array $aResult, string $sFolderName, bool $bIsWritable) : void
	{
		if (\is_array($aResult))
		{
			$oImapResponse = null;
			$oResult = FolderInformation::NewInstance($sFolderName, $bIsWritable);

			foreach ($aResult as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
			{
				if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType)
				{
					if (\count($oImapResponse->ResponseList) > 2 &&
						'FLAGS' === $oImapResponse->ResponseList[1] && \is_array($oImapResponse->ResponseList[2]))
					{
						$oResult->Flags = $oImapResponse->ResponseList[2];
					}

					if (is_array($oImapResponse->OptionalResponse) && \count($oImapResponse->OptionalResponse) > 1)
					{
						if ('PERMANENTFLAGS' === $oImapResponse->OptionalResponse[0] &&
							is_array($oImapResponse->OptionalResponse[1]))
						{
							$oResult->PermanentFlags = $oImapResponse->OptionalResponse[1];
						}
						else if ('UIDVALIDITY' === $oImapResponse->OptionalResponse[0] &&
							isset($oImapResponse->OptionalResponse[1]))
						{
							$oResult->Uidvalidity = $oImapResponse->OptionalResponse[1];
						}
						else if ('UNSEEN' === $oImapResponse->OptionalResponse[0] &&
							isset($oImapResponse->OptionalResponse[1]) &&
							is_numeric($oImapResponse->OptionalResponse[1]))
						{
							$oResult->Unread = (int) $oImapResponse->OptionalResponse[1];
						}
						else if ('UIDNEXT' === $oImapResponse->OptionalResponse[0] &&
							isset($oImapResponse->OptionalResponse[1]))
						{
							$oResult->Uidnext = $oImapResponse->OptionalResponse[1];
						}
						else if ('HIGHESTMODSEQ' === $oImapResponse->OptionalResponse[0] &&
							isset($oImapResponse->OptionalResponse[1]) &&
							\is_numeric($oImapResponse->OptionalResponse[1]))
						{
							$oResult->HighestModSeq = \trim($oImapResponse->OptionalResponse[1]);
						}
					}

					if (\count($oImapResponse->ResponseList) > 2 &&
						\is_string($oImapResponse->ResponseList[2]) &&
						\is_numeric($oImapResponse->ResponseList[1]))
					{
						switch($oImapResponse->ResponseList[2])
						{
							case 'EXISTS':
								$oResult->Exists = (int) $oImapResponse->ResponseList[1];
								break;
							case 'RECENT':
								$oResult->Recent = (int) $oImapResponse->ResponseList[1];
								break;
						}
					}
				}
			}

			$this->oCurrentFolderInfo = $oResult;
		}
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	protected function selectOrExamineFolder(string $sFolderName, bool $bIsWritable, bool $bReSelectSameFolders) : self
	{
		if (!$bReSelectSameFolders)
		{
			if ($this->oCurrentFolderInfo &&
				$sFolderName === $this->oCurrentFolderInfo->FolderName &&
				$bIsWritable === $this->oCurrentFolderInfo->IsWritable)
			{
				return $this;
			}
		}

		if (!strlen(\trim($sFolderName)))
		{
			throw new \MailSo\Base\Exceptions\InvalidArgumentException();
		}

		$this->SendRequest(($bIsWritable) ? 'SELECT' : 'EXAMINE',
			array($this->EscapeString($sFolderName)));

		$this->initCurrentFolderInformation(
			$this->parseResponseWithValidation(), $sFolderName, $bIsWritable);

		$this->bIsSelected = true;

		return $this;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderSelect(string $sFolderName, bool $bReSelectSameFolders = false) : self
	{
		return $this->selectOrExamineFolder($sFolderName, true, $bReSelectSameFolders);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderExamine(string $sFolderName, bool $bReSelectSameFolders = false) : self
	{
		return $this->selectOrExamineFolder($sFolderName, $this->__FORCE_SELECT_ON_EXAMINE__, $bReSelectSameFolders);
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function FolderUnSelect() : self
	{
		if ($this->IsSelected() && $this->IsSupported('UNSELECT'))
		{
			$this->SendRequestWithCheck('UNSELECT');
			$this->bIsSelected = false;
		}

		return $this;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function Fetch(array $aInputFetchItems, string $sIndexRange, bool $bIndexIsUid) : array
	{
		$sIndexRange = (string) $sIndexRange;
		if (!strlen(\trim($sIndexRange)))
		{
			$this->writeLogException(
				new \MailSo\Base\Exceptions\InvalidArgumentException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		$aFetchItems = \MailSo\Imap\Enumerations\FetchType::ChangeFetchItemsBefourRequest($aInputFetchItems);
		foreach ($aFetchItems as $sName => $mItem)
		{
			if (0 < \strlen($sName) && '' !== $mItem)
			{
				if (null === $this->aFetchCallbacks)
				{
					$this->aFetchCallbacks = array();
				}

				$this->aFetchCallbacks[$sName] = $mItem;
			}
		}

		$this->SendRequest((($bIndexIsUid) ? 'UID ' : '').'FETCH', array($sIndexRange, \array_keys($aFetchItems)));
		$aResult = $this->validateResponse($this->parseResponse());
		$this->aFetchCallbacks = null;

		$aReturn = array();
		$oImapResponse = null;
		foreach ($aResult as $oImapResponse)
		{
			if (FetchResponse::IsValidFetchImapResponse($oImapResponse))
			{
				if (FetchResponse::IsNotEmptyFetchImapResponse($oImapResponse))
				{
					$aReturn[] = FetchResponse::NewInstance($oImapResponse);
				}
				else
				{
					if ($this->oLogger)
					{
						$this->oLogger->Write('Skipped Imap Response! ['.$oImapResponse->ToLine().']', \MailSo\Log\Enumerations\Type::NOTICE);
					}
				}
			}
		}

		return $aReturn;
	}


	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function Quota() : ?array
	{
		$aReturn = null;
		if ($this->IsSupported('QUOTA'))
		{
			$this->SendRequest('GETQUOTAROOT "INBOX"');
			$aResult = $this->parseResponseWithValidation();

			$aReturn = array(0, 0);
			$oImapResponse = null;
			foreach ($aResult as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
			{
				if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType
					&& 'QUOTA' === $oImapResponse->StatusOrIndex
					&& \is_array($oImapResponse->ResponseList)
					&& isset($oImapResponse->ResponseList[3])
					&& \is_array($oImapResponse->ResponseList[3])
					&& 2 < \count($oImapResponse->ResponseList[3])
					&& 'STORAGE' === \strtoupper($oImapResponse->ResponseList[3][0])
					&& \is_numeric($oImapResponse->ResponseList[3][1])
					&& \is_numeric($oImapResponse->ResponseList[3][2])
				)
				{
					$aReturn = array(
						(int) $oImapResponse->ResponseList[3][1],
						(int) $oImapResponse->ResponseList[3][2],
						0,
						0
					);

					if (5 < \count($oImapResponse->ResponseList[3])
						&& 'MESSAGE' === \strtoupper($oImapResponse->ResponseList[3][3])
						&& \is_numeric($oImapResponse->ResponseList[3][4])
						&& \is_numeric($oImapResponse->ResponseList[3][5])
					)
					{
						$aReturn[2] = (int) $oImapResponse->ResponseList[3][4];
						$aReturn[3] = (int) $oImapResponse->ResponseList[3][5];
					}
				}
			}
		}

		return $aReturn;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageSimpleSort(array $aSortTypes, string $sSearchCriterias = 'ALL', bool $bReturnUid = true) : array
	{
		$sCommandPrefix = ($bReturnUid) ? 'UID ' : '';
		$sSearchCriterias = !strlen(\trim($sSearchCriterias)) || '*' === $sSearchCriterias
			? 'ALL' : $sSearchCriterias;

		if (!\is_array($aSortTypes) || 0 === \count($aSortTypes))
		{
			$this->writeLogException(
				new \MailSo\Base\Exceptions\InvalidArgumentException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}
		else if (!$this->IsSupported('SORT'))
		{
			$this->writeLogException(
				new \MailSo\Base\Exceptions\InvalidArgumentException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		$aRequest = array();
		$aRequest[] = $aSortTypes;
		$aRequest[] = \MailSo\Base\Utils::IsAscii($sSearchCriterias) ? 'US-ASCII' : 'UTF-8';
		$aRequest[] = $sSearchCriterias;

		$sCmd = 'SORT';

		$this->SendRequest($sCommandPrefix.$sCmd, $aRequest);
		$aResult = $this->parseResponseWithValidation();

		$aReturn = array();
		$oImapResponse = null;
		foreach ($aResult as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
		{
			if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType
				&& ($sCmd === $oImapResponse->StatusOrIndex ||
					($bReturnUid && 'UID' === $oImapResponse->StatusOrIndex) && !empty($oImapResponse->ResponseList[2]) &&
						$sCmd === $oImapResponse->ResponseList[2])
				&& \is_array($oImapResponse->ResponseList)
				&& 2 < \count($oImapResponse->ResponseList))
			{
				$iStart = 2;
				if ($bReturnUid && 'UID' === $oImapResponse->StatusOrIndex &&
					!empty($oImapResponse->ResponseList[2]) &&
					$sCmd === $oImapResponse->ResponseList[2])
				{
					$iStart = 3;
				}

				for ($iIndex = $iStart, $iLen = \count($oImapResponse->ResponseList); $iIndex < $iLen; $iIndex++)
				{
					$aReturn[] = (int) $oImapResponse->ResponseList[$iIndex];
				}
			}
		}

		return $aReturn;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	private function simpleESearchOrESortHelper(bool $bSort = false, string $sSearchCriterias = 'ALL', array $aSearchOrSortReturn = null, bool $bReturnUid = true, string $sLimit = '', string $sCharset = '', array $aSortTypes = null) : array
	{
		$sCommandPrefix = ($bReturnUid) ? 'UID ' : '';
		$sSearchCriterias = 0 === \strlen($sSearchCriterias) || '*' === $sSearchCriterias
			? 'ALL' : $sSearchCriterias;

		$sCmd = $bSort ? 'SORT': 'SEARCH';
		if ($bSort && (!\is_array($aSortTypes) || 0 === \count($aSortTypes) || !$this->IsSupported('SORT')))
		{
			$this->writeLogException(
				new \MailSo\Base\Exceptions\InvalidArgumentException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		if (!$this->IsSupported($bSort ? 'ESORT' : 'ESEARCH'))
		{
			$this->writeLogException(
				new \MailSo\Base\Exceptions\InvalidArgumentException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		if (!\is_array($aSearchOrSortReturn) || 0 === \count($aSearchOrSortReturn))
		{
			$aSearchOrSortReturn = array('ALL');
		}

		$aRequest = array();
		if ($bSort)
		{
			$aRequest[] = 'RETURN';
			$aRequest[] = $aSearchOrSortReturn;

			$aRequest[] = $aSortTypes;
			$aRequest[] = \MailSo\Base\Utils::IsAscii($sSearchCriterias) ? 'US-ASCII' : 'UTF-8';
		}
		else
		{
			if (0 < \strlen($sCharset))
			{
				$aRequest[] = 'CHARSET';
				$aRequest[] = \strtoupper($sCharset);
			}

			$aRequest[] = 'RETURN';
			$aRequest[] = $aSearchOrSortReturn;
		}

		$aRequest[] = $sSearchCriterias;

		if (0 < \strlen($sLimit))
		{
			$aRequest[] = $sLimit;
		}

		$this->SendRequest($sCommandPrefix.$sCmd, $aRequest);
		$sRequestTag = $this->getCurrentTag();

		$aResult = array();
		$aResponse = $this->parseResponseWithValidation();

		if (\is_array($aResponse))
		{
			$oImapResponse = null;
			foreach ($aResponse as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
			{
				if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType
					&& ('ESEARCH' === $oImapResponse->StatusOrIndex || 'ESORT' === $oImapResponse->StatusOrIndex)
					&& \is_array($oImapResponse->ResponseList)
					&& isset($oImapResponse->ResponseList[2], $oImapResponse->ResponseList[2][0], $oImapResponse->ResponseList[2][1])
					&& 'TAG' === $oImapResponse->ResponseList[2][0] && $sRequestTag === $oImapResponse->ResponseList[2][1]
					&& (!$bReturnUid || ($bReturnUid && !empty($oImapResponse->ResponseList[3]) && 'UID' === $oImapResponse->ResponseList[3]))
				)
				{
					$iStart = 3;
					foreach ($oImapResponse->ResponseList as $iIndex => $mItem)
					{
						if ($iIndex >= $iStart)
						{
							switch ($mItem)
							{
								case 'ALL':
								case 'MAX':
								case 'MIN':
								case 'COUNT':
									if (isset($oImapResponse->ResponseList[$iIndex + 1]))
									{
										$aResult[$mItem] = $oImapResponse->ResponseList[$iIndex + 1];
									}
									break;
							}
						}
					}
				}
			}
		}

		return $aResult;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageSimpleESearch(string $sSearchCriterias = 'ALL', array $aSearchReturn = null, bool $bReturnUid = true, string $sLimit = '', string $sCharset = '') : array
	{
		return $this->simpleESearchOrESortHelper(false, $sSearchCriterias, $aSearchReturn, $bReturnUid, $sLimit, $sCharset);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageSimpleESort(array $aSortTypes, string $sSearchCriterias = 'ALL', array $aSearchReturn = null, bool $bReturnUid = true, string $sLimit = '') : array
	{
		return $this->simpleESearchOrESortHelper(true, $sSearchCriterias, $aSearchReturn, $bReturnUid, $sLimit, '', $aSortTypes);
	}

	private function findLastResponse(array $aResult) : \MailSo\Imap\Response
	{
		$oResult = null;
		if (\is_array($aResult) && 0 < \count($aResult))
		{
			$oResult = $aResult[\count($aResult) - 1];
			if (!($oResult instanceof \MailSo\Imap\Response))
			{
				$oResult = null;
			}
		}

		return $oResult;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageSimpleSearch(string $sSearchCriterias = 'ALL', bool $bReturnUid = true, string $sCharset = '') : array
	{
		$sCommandPrefix = ($bReturnUid) ? 'UID ' : '';
		$sSearchCriterias = 0 === \strlen($sSearchCriterias) || '*' === $sSearchCriterias
			? 'ALL' : $sSearchCriterias;

		$aRequest = array();
		if (0 < \strlen($sCharset))
		{
			$aRequest[] = 'CHARSET';
			$aRequest[] = \strtoupper($sCharset);
		}

		$aRequest[] = $sSearchCriterias;

		$sCmd = 'SEARCH';

		$sCont = $this->SendRequest($sCommandPrefix.$sCmd, $aRequest, true);
		if ('' !== $sCont)
		{
			$aResult = $this->parseResponseWithValidation();
			$oItem = $this->findLastResponse($aResult);

			if ($oItem && \MailSo\Imap\Enumerations\ResponseType::CONTINUATION === $oItem->ResponseType)
			{
				$aParts = explode("\r\n", $sCont);
				foreach ($aParts as $sLine)
				{
					$this->sendRaw($sLine);

					$aResult = $this->parseResponseWithValidation();
					$oItem = $this->findLastResponse($aResult);
					if ($oItem && \MailSo\Imap\Enumerations\ResponseType::CONTINUATION === $oItem->ResponseType)
					{
						continue;
					}
				}
			}
		}
		else
		{
			$aResult = $this->parseResponseWithValidation();
		}

		$aReturn = array();
		$oImapResponse = null;
		foreach ($aResult as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
		{
			if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType
				&& ($sCmd === $oImapResponse->StatusOrIndex ||
					($bReturnUid && 'UID' === $oImapResponse->StatusOrIndex) && !empty($oImapResponse->ResponseList[2]) &&
						$sCmd === $oImapResponse->ResponseList[2])
				&& \is_array($oImapResponse->ResponseList)
				&& 2 < count($oImapResponse->ResponseList))
			{
				$iStart = 2;
				if ($bReturnUid && 'UID' === $oImapResponse->StatusOrIndex &&
					!empty($oImapResponse->ResponseList[2]) &&
					$sCmd === $oImapResponse->ResponseList[2])
				{
					$iStart = 3;
				}

				for ($iIndex = $iStart, $iLen = \count($oImapResponse->ResponseList); $iIndex < $iLen; $iIndex++)
				{
					$aReturn[] = (int) $oImapResponse->ResponseList[$iIndex];
				}
			}
		}

		$aReturn = \array_reverse($aReturn);
		return $aReturn;
	}

	/**
	 * @param mixed $aValue
	 *
	 * @return mixed
	 */
	private function validateThreadItem(array $aValue)
	{
		$mResult = false;
		if (\is_numeric($aValue))
		{
			$mResult = (int) $aValue;
			if (0 >= $mResult)
			{
				$mResult = false;
			}
		}
		else if (\is_array($aValue))
		{
			if (1 === \count($aValue) && \is_numeric($aValue[0]))
			{
				$mResult = (int) $aValue[0];
				if (0 >= $mResult)
				{
					$mResult = false;
				}
			}
			else
			{
				$mResult = array();
				foreach ($aValue as $aValueItem)
				{
					$mTemp = $this->validateThreadItem($aValueItem);
					if (false !== $mTemp)
					{
						$mResult[] = $mTemp;
					}
				}
			}
		}

		return $mResult;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageSimpleThread(string $sSearchCriterias = 'ALL', bool $bReturnUid = true, string $sCharset = \MailSo\Base\Enumerations\Charset::UTF_8) : array
	{
		$sCommandPrefix = ($bReturnUid) ? 'UID ' : '';
		$sSearchCriterias = !strlen(\trim($sSearchCriterias)) || '*' === $sSearchCriterias
			? 'ALL' : $sSearchCriterias;

		$sThreadType = '';
		switch (true)
		{
			case $this->IsSupported('THREAD=REFS'):
				$sThreadType = 'REFS';
				break;
			case $this->IsSupported('THREAD=REFERENCES'):
				$sThreadType = 'REFERENCES';
				break;
			case $this->IsSupported('THREAD=ORDEREDSUBJECT'):
				$sThreadType = 'ORDEREDSUBJECT';
				break;
			default:
				$this->writeLogException(
					new Exceptions\RuntimeException('Thread is not supported'),
					\MailSo\Log\Enumerations\Type::ERROR, true);
				break;
		}

		$aRequest = array();
		$aRequest[] = $sThreadType;
		$aRequest[] = \strtoupper($sCharset);
		$aRequest[] = $sSearchCriterias;

		$sCmd = 'THREAD';

		$this->SendRequest($sCommandPrefix.$sCmd, $aRequest);
		$aResult = $this->parseResponseWithValidation();

		$aReturn = array();
		$oImapResponse = null;

		foreach ($aResult as /* @var $oImapResponse \MailSo\Imap\Response */ $oImapResponse)
		{
			if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType
				&& ($sCmd === $oImapResponse->StatusOrIndex ||
					($bReturnUid && 'UID' === $oImapResponse->StatusOrIndex) && !empty($oImapResponse->ResponseList[2]) &&
						$sCmd === $oImapResponse->ResponseList[2])
				&& \is_array($oImapResponse->ResponseList)
				&& 2 < \count($oImapResponse->ResponseList))
			{
				$iStart = 2;
				if ($bReturnUid && 'UID' === $oImapResponse->StatusOrIndex &&
					!empty($oImapResponse->ResponseList[2]) &&
					$sCmd === $oImapResponse->ResponseList[2])
				{
					$iStart = 3;
				}

				for ($iIndex = $iStart, $iLen = \count($oImapResponse->ResponseList); $iIndex < $iLen; $iIndex++)
				{
					$aNewValue = $this->validateThreadItem($oImapResponse->ResponseList[$iIndex]);
					if (false !== $aNewValue)
					{
						$aReturn[] = $aNewValue;
					}
				}
			}
		}

		return $aReturn;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageCopy(string $sToFolder, string $sIndexRange, bool $bIndexIsUid) : self
	{
		if (0 === \strlen($sIndexRange))
		{
			$this->writeLogException(
				new \MailSo\Base\Exceptions\InvalidArgumentException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		$sCommandPrefix = ($bIndexIsUid) ? 'UID ' : '';
		return $this->SendRequestWithCheck($sCommandPrefix.'COPY',
			array($sIndexRange, $this->EscapeString($sToFolder)));
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageMove(string $sToFolder, string $sIndexRange, bool $bIndexIsUid) : self
	{
		if (0 === \strlen($sIndexRange))
		{
			$this->writeLogException(
				new \MailSo\Base\Exceptions\InvalidArgumentException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		if (!$this->IsSupported('MOVE'))
		{
			$this->writeLogException(
				new Exceptions\RuntimeException('Move is not supported'),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		$sCommandPrefix = ($bIndexIsUid) ? 'UID ' : '';
		return $this->SendRequestWithCheck($sCommandPrefix.'MOVE',
			array($sIndexRange, $this->EscapeString($sToFolder)));
	}

	/**
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageExpunge(string $sUidRangeIfSupported = '', bool $bForceUidExpunge = false, bool $bExpungeAll = false) : self
	{
		$sUidRangeIfSupported = \trim($sUidRangeIfSupported);

		$sCmd = 'EXPUNGE';
		$aArguments = array();

		if (!$bExpungeAll && $bForceUidExpunge && 0 < \strlen($sUidRangeIfSupported) && $this->IsSupported('UIDPLUS'))
		{
			$sCmd = 'UID '.$sCmd;
			$aArguments = array($sUidRangeIfSupported);
		}

		return $this->SendRequestWithCheck($sCmd, $aArguments);
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageStoreFlag(string $sIndexRange, bool $bIndexIsUid, array $aInputStoreItems, string $sStoreAction) : self
	{
		if (!strlen(\trim($sIndexRange)) ||
			!strlen(\trim($sStoreAction)) ||
			0 === \count($aInputStoreItems))
		{
			return false;
		}

		$sCmd = ($bIndexIsUid) ? 'UID STORE' : 'STORE';
		return $this->SendRequestWithCheck($sCmd, array($sIndexRange, $sStoreAction, $aInputStoreItems));
	}

	/**
	 * @param resource $rMessageAppendStream
	 *
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function MessageAppendStream(string $sFolderName, $rMessageAppendStream, int $iStreamSize, array $aAppendFlags = null, int &$iUid = null, int $iDateTime = 0) : self
	{
		$aData = array($this->EscapeString($sFolderName), $aAppendFlags);
		if (0 < $iDateTime)
		{
			$aData[] = $this->EscapeString(\gmdate('d-M-Y H:i:s', $iDateTime).' +0000');
		}

		$aData[] = '{'.$iStreamSize.'}';

		$this->SendRequest('APPEND', $aData);
		$this->parseResponseWithValidation();

		$this->writeLog('Write to connection stream', \MailSo\Log\Enumerations\Type::NOTE);

		\MailSo\Base\Utils::MultipleStreamWriter($rMessageAppendStream, array($this->rConnect));

		$this->sendRaw('');
		$this->parseResponseWithValidation();

		if (null !== $iUid)
		{
			$aLastResponse = $this->GetLastResponse();
			if (\is_array($aLastResponse) && 0 < \count($aLastResponse) && $aLastResponse[\count($aLastResponse) - 1])
			{
				$oLast = $aLastResponse[count($aLastResponse) - 1];
				if ($oLast && \MailSo\Imap\Enumerations\ResponseType::TAGGED === $oLast->ResponseType && \is_array($oLast->OptionalResponse))
				{
					if (0 < \strlen($oLast->OptionalResponse[0]) &&
						0 < \strlen($oLast->OptionalResponse[2]) &&
						'APPENDUID' === strtoupper($oLast->OptionalResponse[0]) &&
						\is_numeric($oLast->OptionalResponse[2])
					)
					{
						$iUid = (int) $oLast->OptionalResponse[2];
					}
				}
			}
		}

		return $this;
	}

	public function FolderCurrentInformation() : \MailSo\Imap\FolderInformation
	{
		return $this->oCurrentFolderInfo;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 */
	public function SendRequest(string $sCommand, array $aParams = array(), bool $bBreakOnLiteral = false) : string
	{
		$sCommand = \trim($sCommand);
		if (!\strlen($sCommand))
		{
			$this->writeLogException(
				new \MailSo\Base\Exceptions\InvalidArgumentException(),
				\MailSo\Log\Enumerations\Type::ERROR, true);
		}

		$this->IsConnected(true);

		$sTag = $this->getNewTag();

		$sRealCommand = $sTag.' '.$sCommand.$this->prepareParamLine($aParams);

		$sFakeCommand = '';
		if ($aFakeParams = $this->secureRequestParams($sCommand, $aParams)) {
			$sFakeCommand = $sTag.' '.$sCommand.$this->prepareParamLine($aFakeParams);
		}

		$this->aTagTimeouts[$sTag] = \microtime(true);

		if ($bBreakOnLiteral && !\preg_match('/\d\+\}\r\n/', $sRealCommand))
		{
			$iPos = \strpos($sRealCommand, "}\r\n");
			if (false !== $iPos)
			{
				$iFakePos = \strpos($sFakeCommand, "}\r\n");

				$this->sendRaw(\substr($sRealCommand, 0, $iPos + 1), true,
					false !== $iFakePos ? \substr($sFakeCommand, 0, $iFakePos + 3) : '');

				return \substr($sRealCommand, $iPos + 3);
			}
		}

		$this->sendRaw($sRealCommand, true, $sFakeCommand);
		return '';
	}

	private function secureRequestParams(string $sCommand, array $aParams) : ?array
	{
		if ('LOGIN' === $sCommand && isset($aParams[1])) {
			$aParams[1] = '"********"';
			return $aParams;
		}
		return null;
	}

	/**
	 * @throws \MailSo\Base\Exceptions\InvalidArgumentException
	 * @throws \MailSo\Net\Exceptions\Exception
	 * @throws \MailSo\Imap\Exceptions\Exception
	 */
	public function SendRequestWithCheck(string $sCommand, array $aParams = array(), bool $bFindCapa = false) : self
	{
		$this->SendRequest($sCommand, $aParams);
		$this->parseResponseWithValidation(null, $bFindCapa);

		return $this;
	}

	public function GetLastResponse() : array
	{
		return $this->aLastResponse;
	}

	/**
	 * @param mixed $aResult
	 *
	 * @throws \MailSo\Imap\Exceptions\ResponseNotFoundException
	 * @throws \MailSo\Imap\Exceptions\InvalidResponseException
	 * @throws \MailSo\Imap\Exceptions\NegativeResponseException
	 */
	private function validateResponse(array $aResult) : array
	{
		if (!\is_array($aResult) || 0 === $iCnt = \count($aResult))
		{
			$this->writeLogException(
				new Exceptions\ResponseNotFoundException(),
				\MailSo\Log\Enumerations\Type::WARNING, true);
		}

		if ($aResult[$iCnt - 1]->ResponseType !== \MailSo\Imap\Enumerations\ResponseType::CONTINUATION)
		{
			if (!$aResult[$iCnt - 1]->IsStatusResponse)
			{
				$this->writeLogException(
					new Exceptions\InvalidResponseException($aResult),
					\MailSo\Log\Enumerations\Type::WARNING, true);
			}

			if (\MailSo\Imap\Enumerations\ResponseStatus::OK !== $aResult[$iCnt - 1]->StatusOrIndex)
			{
				$this->writeLogException(
					new Exceptions\NegativeResponseException($aResult),
					\MailSo\Log\Enumerations\Type::WARNING, true);
			}
		}

		return $aResult;
	}

	protected function parseResponse(string $sEndTag = null, bool $bFindCapa = false) : array
	{
		if (\is_resource($this->rConnect))
		{
			$oImapResponse = null;
			$sEndTag = (null === $sEndTag) ? $this->getCurrentTag() : $sEndTag;

			while (true)
			{
				$oImapResponse = Response::NewInstance();

				$this->partialParseResponseBranch($oImapResponse);

				if ($oImapResponse)
				{
					if (\MailSo\Imap\Enumerations\ResponseType::UNKNOWN === $oImapResponse->ResponseType)
					{
						return false;
					}

					if ($bFindCapa)
					{
						$this->initCapabilityImapResponse($oImapResponse);
					}

					$this->aPartialResponses[] = $oImapResponse;
					if ($sEndTag === $oImapResponse->Tag || \MailSo\Imap\Enumerations\ResponseType::CONTINUATION === $oImapResponse->ResponseType)
					{
						if (isset($this->aTagTimeouts[$sEndTag]))
						{
							$this->writeLog((\microtime(true) - $this->aTagTimeouts[$sEndTag]).' ('.$sEndTag.')',
								\MailSo\Log\Enumerations\Type::TIME);

							unset($this->aTagTimeouts[$sEndTag]);
						}

						break;
					}
				}
				else
				{
					return false;
				}

				unset($oImapResponse);
			}
		}

		$this->iResponseBufParsedPos = 0;
		$this->aLastResponse = $this->aPartialResponses;
		$this->aPartialResponses = array();

		return $this->aLastResponse;
	}

	private function parseResponseWithValidation(string $sEndTag = null, bool $bFindCapa = false) : array
	{
		return $this->validateResponse($this->parseResponse($sEndTag, $bFindCapa));
	}

	/**
	 * @param \MailSo\Imap\Response $oImapResponse
	 */
	private function initCapabilityImapResponse($oImapResponse) : void
	{
		if (\MailSo\Imap\Enumerations\ResponseType::UNTAGGED === $oImapResponse->ResponseType
			&& \is_array($oImapResponse->ResponseList))
		{
			$aList = null;
			if (isset($oImapResponse->ResponseList[1]) && \is_string($oImapResponse->ResponseList[1]) &&
				'CAPABILITY' === \strtoupper($oImapResponse->ResponseList[1]))
			{
				$aList = \array_slice($oImapResponse->ResponseList, 2);
			}
			else if ($oImapResponse->OptionalResponse && \is_array($oImapResponse->OptionalResponse) &&
				1 < \count($oImapResponse->OptionalResponse) && \is_string($oImapResponse->OptionalResponse[0]) &&
				'CAPABILITY' === \strtoupper($oImapResponse->OptionalResponse[0]))
			{
				$aList = \array_slice($oImapResponse->OptionalResponse, 1);
			}

			if (\is_array($aList) && 0 < \count($aList))
			{
				$this->aCapabilityItems = \array_map('strtoupper', $aList);
			}
		}
	}

	/**
	 * @return array|string
	 * @throws \MailSo\Net\Exceptions\Exception
	 */
	private function partialParseResponseBranch(&$oImapResponse, int $iStackIndex = -1,
		bool $bTreatAsAtom = false, string $sParentToken = '', string $sOpenBracket = '')
	{
		$mNull = null;

		$iStackIndex++;
		$iPos = $this->iResponseBufParsedPos;

		$sPreviousAtomUpperCase = null;
		$bIsEndOfList = false;
		$bIsClosingBracketSquare = false;
		$iLiteralLen = 0;
		$iBufferEndIndex = 0;
		$iDebugCount = 0;

		$rImapLiteralStream = null;

		$bIsGotoDefault = false;
		$bIsGotoLiteral = false;
		$bIsGotoLiteralEnd = false;
		$bIsGotoAtomBracket = false;
		$bIsGotoNotAtomBracket = false;

		$bCountOneInited = false;
		$bCountTwoInited = false;

		$sAtomBuilder = $bTreatAsAtom ? '' : null;
		$aList = array();
		if (null !== $oImapResponse)
		{
			$aList =& $oImapResponse->ResponseList;
		}

		while (!$bIsEndOfList)
		{
			$iDebugCount++;
			if (100000 === $iDebugCount)
			{
				$this->Logger()->Write('PartialParseOver: '.$iDebugCount, \MailSo\Log\Enumerations\Type::ERROR);
			}

			if ($this->bNeedNext)
			{
				$iPos = 0;
				$this->getNextBuffer();
				$this->iResponseBufParsedPos = $iPos;
				$this->bNeedNext = false;
			}

			$sChar = null;
			if ($bIsGotoDefault)
			{
				$sChar = 'GOTO_DEFAULT';
				$bIsGotoDefault = false;
			}
			else if ($bIsGotoLiteral)
			{
				$bIsGotoLiteral = false;
				$bIsGotoLiteralEnd = true;

				if ($this->partialResponseLiteralCallbackCallable(
					$sParentToken, null === $sPreviousAtomUpperCase ? '' : \strtoupper($sPreviousAtomUpperCase), $this->rConnect, $iLiteralLen))
				{
					if (!$bTreatAsAtom)
					{
						$aList[] = '';
					}
				}
				else
				{
					$sLiteral = '';
					$iRead = $iLiteralLen;

					while (0 < $iRead)
					{
						$sAddRead = \fread($this->rConnect, $iRead);
						if (false === $sAddRead)
						{
							$sLiteral = false;
							break;
						}

						$sLiteral .= $sAddRead;
						$iRead -= \strlen($sAddRead);

						\MailSo\Base\Utils::ResetTimeLimit();
					}

					if (false !== $sLiteral)
					{
						$iLiteralSize = \strlen($sLiteral);
						\MailSo\Base\Loader::IncStatistic('NetRead', $iLiteralSize);
						if ($iLiteralLen !== $iLiteralSize)
						{
							$this->writeLog('Literal stream read warning "read '.$iLiteralSize.' of '.
								$iLiteralLen.'" bytes', \MailSo\Log\Enumerations\Type::WARNING);
						}

						if (!$bTreatAsAtom)
						{
							$aList[] = $sLiteral;

							if (\MailSo\Config::$LogSimpleLiterals)
							{
								$this->writeLog('{'.\strlen($sLiteral).'} '.$sLiteral, \MailSo\Log\Enumerations\Type::INFO);
							}
						}
					}
					else
					{
						$this->writeLog('Can\'t read imap stream', \MailSo\Log\Enumerations\Type::NOTE);
					}

					unset($sLiteral);
				}

				continue;
			}
			else if ($bIsGotoLiteralEnd)
			{
				$rImapLiteralStream = null;
				$sPreviousAtomUpperCase = null;
				$this->bNeedNext = true;
				$bIsGotoLiteralEnd = false;

				continue;
			}
			else if ($bIsGotoAtomBracket)
			{
				if ($bTreatAsAtom)
				{
					$sAtomBlock = $this->partialParseResponseBranch($mNull, $iStackIndex, true,
						null === $sPreviousAtomUpperCase ? '' : \strtoupper($sPreviousAtomUpperCase), $sOpenBracket);

					$sAtomBuilder .= $sAtomBlock;
					$iPos = $this->iResponseBufParsedPos;
					$sAtomBuilder .= ($bIsClosingBracketSquare) ? ']' : ')';
				}

				$sPreviousAtomUpperCase = null;
				$bIsGotoAtomBracket = false;

				continue;
			}
			else if ($bIsGotoNotAtomBracket)
			{
				$aSubItems = $this->partialParseResponseBranch($mNull, $iStackIndex, false,
					null === $sPreviousAtomUpperCase ? '' : \strtoupper($sPreviousAtomUpperCase), $sOpenBracket);

				$aList[] = $aSubItems;
				$iPos = $this->iResponseBufParsedPos;
				$sPreviousAtomUpperCase = null;
				if (null !== $oImapResponse && $oImapResponse->IsStatusResponse)
				{
					$oImapResponse->OptionalResponse = $aSubItems;

					$bIsGotoDefault = true;
					$bIsGotoNotAtomBracket = false;
					continue;
				}
				$bIsGotoNotAtomBracket = false;

				continue;
			}
			else
			{
				$iBufferEndIndex = \strlen($this->sResponseBuffer) - 3;
				$this->bResponseBufferChanged = false;

				if ($iPos > $iBufferEndIndex)
				{
					break;
				}

				$sChar = $this->sResponseBuffer[$iPos];
			}

			switch (true)
			{
				case ']' === $sChar:
					$iPos++;
					$sPreviousAtomUpperCase = null;
					$bIsEndOfList = true;
					break;
				case ')' === $sChar:
					$iPos++;
					$sPreviousAtomUpperCase = null;
					$bIsEndOfList = true;
					break;
				case ' ' === $sChar:
					if ($bTreatAsAtom)
					{
						$sAtomBuilder .= ' ';
					}
					$iPos++;
					break;
				case '[' === $sChar:
					$bIsClosingBracketSquare = true;
				case '(' === $sChar:
					if ('(' === $sChar)
					{
						$bIsClosingBracketSquare = false;
					}

					if ($bTreatAsAtom)
					{
						$sAtomBuilder .= $bIsClosingBracketSquare ? '[' : '(';
					}
					$iPos++;

					$this->iResponseBufParsedPos = $iPos;
					if ($bTreatAsAtom)
					{
						$bIsGotoAtomBracket = true;
						$sOpenBracket = $bIsClosingBracketSquare ? '[' : '(';
					}
					else
					{
						$bIsGotoNotAtomBracket = true;
						$sOpenBracket = $bIsClosingBracketSquare ? '[' : '(';
					}
					break;
				case '{' === $sChar:
					$bIsLiteralParsed = false;
					$mLiteralEndPos = \strpos($this->sResponseBuffer, '}', $iPos);
					if (false !== $mLiteralEndPos && $mLiteralEndPos > $iPos)
					{
						$sLiteralLenAsString = \substr($this->sResponseBuffer, $iPos + 1, $mLiteralEndPos - $iPos - 1);
						if (\is_numeric($sLiteralLenAsString))
						{
							$iLiteralLen = (int) $sLiteralLenAsString;
							$bIsLiteralParsed = true;
							$iPos = $mLiteralEndPos + 3;
							$bIsGotoLiteral = true;
							break;
						}
					}
					if (!$bIsLiteralParsed)
					{
						$iPos = $iBufferEndIndex;
					}
					$sPreviousAtomUpperCase = null;
					break;
				case '"' === $sChar:
					$bIsQuotedParsed = false;
					while (true)
					{
						$iClosingPos = $iPos + 1;
						if ($iClosingPos > $iBufferEndIndex)
						{
							break;
						}

						while (true)
						{
							$iClosingPos = \strpos($this->sResponseBuffer, '"', $iClosingPos);
							if (false === $iClosingPos)
							{
								break;
							}

							// TODO
							$iClosingPosNext = $iClosingPos + 1;
							if (
								isset($this->sResponseBuffer[$iClosingPosNext]) &&
								' ' !== $this->sResponseBuffer[$iClosingPosNext] &&
								"\r" !== $this->sResponseBuffer[$iClosingPosNext] &&
								"\n" !== $this->sResponseBuffer[$iClosingPosNext] &&
								']' !== $this->sResponseBuffer[$iClosingPosNext] &&
								')' !== $this->sResponseBuffer[$iClosingPosNext]
								)
							{
								$iClosingPos++;
								continue;
							}

							$iSlashCount = 0;
							while ('\\' === $this->sResponseBuffer[$iClosingPos - $iSlashCount - 1])
							{
								$iSlashCount++;
							}

							if ($iSlashCount % 2 == 1)
							{
								$iClosingPos++;
								continue;
							}
							else
							{
								break;
							}
						}

						if (false === $iClosingPos)
						{
							break;
						}
						else
						{
//							$iSkipClosingPos = 0;
							$bIsQuotedParsed = true;
							if ($bTreatAsAtom)
							{
								$sAtomBuilder .= \strtr(
									\substr($this->sResponseBuffer, $iPos, $iClosingPos - $iPos + 1),
									array('\\\\' => '\\', '\\"' => '"')
								);
							}
							else
							{
								$aList[] = \strtr(
									\substr($this->sResponseBuffer, $iPos + 1, $iClosingPos - $iPos - 1),
									array('\\\\' => '\\', '\\"' => '"')
								);
							}

							$iPos = $iClosingPos + 1;
							break;
						}
					}

					if (!$bIsQuotedParsed)
					{
						$iPos = $iBufferEndIndex;
					}

					$sPreviousAtomUpperCase = null;
					break;

				case 'GOTO_DEFAULT' === $sChar:
				default:
					$iCharBlockStartPos = $iPos;

					if (null !== $oImapResponse && $oImapResponse->IsStatusResponse)
					{
						$iPos = $iBufferEndIndex;

						while ($iPos > $iCharBlockStartPos && $this->sResponseBuffer[$iCharBlockStartPos] === ' ')
						{
							$iCharBlockStartPos++;
						}
					}

					$bIsAtomDone = false;
					while (!$bIsAtomDone && ($iPos <= $iBufferEndIndex))
					{
						$sCharDef = $this->sResponseBuffer[$iPos];
						switch (true)
						{
							case '[' === $sCharDef:
								if (null === $sAtomBuilder)
								{
									$sAtomBuilder = '';
								}

								$sAtomBuilder .= \substr($this->sResponseBuffer, $iCharBlockStartPos, $iPos - $iCharBlockStartPos + 1);

								$iPos++;
								$this->iResponseBufParsedPos = $iPos;

								$sListBlock = $this->partialParseResponseBranch($mNull, $iStackIndex, true,
									null === $sPreviousAtomUpperCase ? '' : \strtoupper($sPreviousAtomUpperCase), '[');

								if (null !== $sListBlock)
								{
									$sAtomBuilder .= $sListBlock.']';
								}

								$iPos = $this->iResponseBufParsedPos;
								$iCharBlockStartPos = $iPos;
								break;
							case ' ' === $sCharDef:
							case ')' === $sCharDef && '(' === $sOpenBracket:
							case ']' === $sCharDef && '[' === $sOpenBracket:
								$bIsAtomDone = true;
								break;
							default:
								$iPos++;
								break;
						}
					}

					if ($iPos > $iCharBlockStartPos || null !== $sAtomBuilder)
					{
						$sLastCharBlock = \substr($this->sResponseBuffer, $iCharBlockStartPos, $iPos - $iCharBlockStartPos);
						if (null === $sAtomBuilder)
						{
							$aList[] = $sLastCharBlock;
							$sPreviousAtomUpperCase = $sLastCharBlock;
						}
						else
						{
							$sAtomBuilder .= $sLastCharBlock;

							if (!$bTreatAsAtom)
							{
								$aList[] = $sAtomBuilder;
								$sPreviousAtomUpperCase = $sAtomBuilder;
								$sAtomBuilder = null;
							}
						}

						if (null !== $oImapResponse)
						{
//							if (1 === \count($aList))
							if (!$bCountOneInited && 1 === \count($aList))
//							if (isset($aList[0]) && !isset($aList[1])) // fast 1 === \count($aList)
							{
								$bCountOneInited = true;

								$oImapResponse->Tag = $aList[0];
								if ('+' === $oImapResponse->Tag)
								{
									$oImapResponse->ResponseType = \MailSo\Imap\Enumerations\ResponseType::CONTINUATION;
								}
								else if ('*' === $oImapResponse->Tag)
								{
									$oImapResponse->ResponseType = \MailSo\Imap\Enumerations\ResponseType::UNTAGGED;
								}
								else if ($this->getCurrentTag() === $oImapResponse->Tag)
								{
									$oImapResponse->ResponseType = \MailSo\Imap\Enumerations\ResponseType::TAGGED;
								}
								else
								{
									$oImapResponse->ResponseType = \MailSo\Imap\Enumerations\ResponseType::UNKNOWN;
								}
							}
//							else if (2 === \count($aList))
							else if (!$bCountTwoInited && 2 === \count($aList))
//							else if (isset($aList[1]) && !isset($aList[2])) // fast 2 === \count($aList)
							{
								$bCountTwoInited = true;

								$oImapResponse->StatusOrIndex = strtoupper($aList[1]);

								if ($oImapResponse->StatusOrIndex == \MailSo\Imap\Enumerations\ResponseStatus::OK ||
									$oImapResponse->StatusOrIndex == \MailSo\Imap\Enumerations\ResponseStatus::NO ||
									$oImapResponse->StatusOrIndex == \MailSo\Imap\Enumerations\ResponseStatus::BAD ||
									$oImapResponse->StatusOrIndex == \MailSo\Imap\Enumerations\ResponseStatus::BYE ||
									$oImapResponse->StatusOrIndex == \MailSo\Imap\Enumerations\ResponseStatus::PREAUTH)
								{
									$oImapResponse->IsStatusResponse = true;
								}
							}
							else if (\MailSo\Imap\Enumerations\ResponseType::CONTINUATION === $oImapResponse->ResponseType)
							{
								$oImapResponse->HumanReadable = $sLastCharBlock;
							}
							else if ($oImapResponse->IsStatusResponse)
							{
								$oImapResponse->HumanReadable = $sLastCharBlock;
							}
						}
					}
			}
		}

		$this->iResponseBufParsedPos = $iPos;
		if (null !== $oImapResponse)
		{
			$this->bNeedNext = true;
			$this->iResponseBufParsedPos = 0;
		}

		if (100000 < $iDebugCount)
		{
			$this->Logger()->Write('PartialParseOverResult: '.$iDebugCount, \MailSo\Log\Enumerations\Type::ERROR);
		}

		return $bTreatAsAtom ? $sAtomBuilder : $aList;
	}

	/**
	 * @param resource $rImapStream
	 */
	private function partialResponseLiteralCallbackCallable(string $sParent, string $sLiteralAtomUpperCase, $rImapStream, int $iLiteralLen) : bool
	{
		$sLiteralAtomUpperCasePeek = '';
		if (0 === \strpos($sLiteralAtomUpperCase, 'BODY'))
		{
			$sLiteralAtomUpperCasePeek = \str_replace('BODY', 'BODY.PEEK', $sLiteralAtomUpperCase);
		}

		$sFetchKey = '';
		if (\is_array($this->aFetchCallbacks))
		{
			if (0 < \strlen($sLiteralAtomUpperCasePeek) && isset($this->aFetchCallbacks[$sLiteralAtomUpperCasePeek]))
			{
				$sFetchKey = $sLiteralAtomUpperCasePeek;
			}
			else if (0 < \strlen($sLiteralAtomUpperCase) && isset($this->aFetchCallbacks[$sLiteralAtomUpperCase]))
			{
				$sFetchKey = $sLiteralAtomUpperCase;
			}
		}

		$bResult = false;
		if (0 < \strlen($sFetchKey) && '' !== $this->aFetchCallbacks[$sFetchKey] &&
			\is_callable($this->aFetchCallbacks[$sFetchKey]))
		{
			$rImapLiteralStream =
				\MailSo\Base\StreamWrappers\Literal::CreateStream($rImapStream, $iLiteralLen);

			$bResult = true;
			$this->writeLog('Start Callback for '.$sParent.' / '.$sLiteralAtomUpperCase.
				' - try to read '.$iLiteralLen.' bytes.', \MailSo\Log\Enumerations\Type::NOTE);

			$this->bRunningCallback = true;

			try
			{
				\call_user_func($this->aFetchCallbacks[$sFetchKey],
					$sParent, $sLiteralAtomUpperCase, $rImapLiteralStream);
			}
			catch (\Throwable $oException)
			{
				$this->writeLog('Callback Exception', \MailSo\Log\Enumerations\Type::NOTICE);
				$this->writeLogException($oException);
			}

			if (\is_resource($rImapLiteralStream))
			{
				$iNotReadLiteralLen = 0;

				$bFeof = \feof($rImapLiteralStream);
				$this->writeLog('End Callback for '.$sParent.' / '.$sLiteralAtomUpperCase.
					' - feof = '.($bFeof ? 'good' : 'BAD'), $bFeof ?
						\MailSo\Log\Enumerations\Type::NOTE : \MailSo\Log\Enumerations\Type::WARNING);

				if (!$bFeof)
				{
					while (!@\feof($rImapLiteralStream))
					{
						$sBuf = @\fread($rImapLiteralStream, 1024 * 1024);
						if (false === $sBuf || 0 === \strlen($sBuf) ||  null === $sBuf)
						{
							break;
						}

						\MailSo\Base\Utils::ResetTimeLimit();
						$iNotReadLiteralLen += \strlen($sBuf);
					}

					if (\is_resource($rImapLiteralStream) && !@\feof($rImapLiteralStream))
					{
						@\stream_get_contents($rImapLiteralStream);
					}
				}

				if (\is_resource($rImapLiteralStream))
				{
					@\fclose($rImapLiteralStream);
				}

				if ($iNotReadLiteralLen > 0)
				{
					$this->writeLog('Not read literal size is '.$iNotReadLiteralLen.' bytes.',
						\MailSo\Log\Enumerations\Type::WARNING);
				}
			}
			else
			{
				$this->writeLog('Literal stream is not resource after callback.',
					\MailSo\Log\Enumerations\Type::WARNING);
			}

			\MailSo\Base\Loader::IncStatistic('NetRead', $iLiteralLen);

			$this->bRunningCallback = false;
		}

		return $bResult;
	}

	private function prepareParamLine(array $aParams = array()) : string
	{
		$sReturn = '';
		if (\is_array($aParams) && 0 < \count($aParams))
		{
			foreach ($aParams as $mParamItem)
			{
				if (\is_array($mParamItem) && 0 < \count($mParamItem))
				{
					$sReturn .= ' ('.\trim($this->prepareParamLine($mParamItem)).')';
				}
				else if (\is_string($mParamItem))
				{
					$sReturn .= ' '.$mParamItem;
				}
			}
		}
		return $sReturn;
	}

	private function getNewTag() : string
	{
		$this->iTagCount++;
		return $this->getCurrentTag();
	}

	private function getCurrentTag() : string
	{
		return self::TAG_PREFIX.$this->iTagCount;
	}

	public function EscapeString(string $sStringForEscape) : string
	{
		return '"'.\str_replace(array('\\', '"'), array('\\\\', '\\"'), $sStringForEscape).'"';
	}

	protected function getLogName() : string
	{
		return 'IMAP';
	}

	/**
	 * @param resource $rConnect
	 */
	public function TestSetValues($rConnect, array $aCapabilityItems = array()) : self
	{
		$this->rConnect = $rConnect;
		$this->aCapabilityItems = $aCapabilityItems;

		return $this;
	}

	public function TestParseResponseWithValidationProxy(string $sEndTag = null, bool $bFindCapa = false) : array
	{
		return $this->parseResponseWithValidation($sEndTag, $bFindCapa);
	}
}
