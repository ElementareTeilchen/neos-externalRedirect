<?php
namespace ElementareTeilchen\Neos\ExternalRedirect;

/*
 * This file is part of the ElementareTeilchen.Neos.ExternalRedirect package.
 *
 * (c) elementare teilchen GmbH - www.elementare-teilchen.de
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

/**
 * A TYPO3 routing exception
 */
class DuplicateRedirectException extends \TYPO3\Neos\Exception
{
    /**
     * @var integer
     */
    protected $statusCode = 500;
}
