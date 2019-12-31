<?php

/**
 * @see       https://github.com/laminas/laminas-mime for the canonical source repository
 * @copyright https://github.com/laminas/laminas-mime/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-mime/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\Mime;

/**
 * @category   Laminas
 * @package    Laminas_Mime
 */
class Message
{

    protected $parts = array();
    protected $mime = null;

    /**
     * Returns the list of all Laminas_Mime_Parts in the message
     *
     * @return array of \Laminas\Mime\Part
     */
    public function getParts()
    {
        return $this->parts;
    }

    /**
     * Sets the given array of Laminas_Mime_Parts as the array for the message
     *
     * @param array $parts
     */
    public function setParts($parts)
    {
        $this->parts = $parts;
    }

    /**
     * Append a new Laminas_Mime_Part to the current message
     *
     * @param \Laminas\Mime\Part $part
     */
    public function addPart(Part $part)
    {
        /**
         * @todo check for duplicate object handle
         */
        $this->parts[] = $part;
    }

    /**
     * Check if message needs to be sent as multipart
     * MIME message or if it has only one part.
     *
     * @return bool
     */
    public function isMultiPart()
    {
        return (count($this->parts) > 1);
    }

    /**
     * Set Laminas_Mime object for the message
     *
     * This can be used to set the boundary specifically or to use a subclass of
     * Laminas_Mime for generating the boundary.
     *
     * @param \Laminas\Mime\Mime $mime
     */
    public function setMime(Mime $mime)
    {
        $this->mime = $mime;
    }

    /**
     * Returns the Laminas_Mime object in use by the message
     *
     * If the object was not present, it is created and returned. Can be used to
     * determine the boundary used in this message.
     *
     * @return \Laminas\Mime\Mime
     */
    public function getMime()
    {
        if ($this->mime === null) {
            $this->mime = new Mime();
        }

        return $this->mime;
    }

    /**
     * Generate MIME-compliant message from the current configuration
     *
     * This can be a multipart message if more than one MIME part was added. If
     * only one part is present, the content of this part is returned. If no
     * part had been added, an empty string is returned.
     *
     * Parts are separated by the mime boundary as defined in Laminas_Mime. If
     * {@link setMime()} has been called before this method, the Laminas_Mime
     * object set by this call will be used. Otherwise, a new Laminas_Mime object
     * is generated and used.
     *
     * @param string $EOL EOL string; defaults to {@link Laminas_Mime::LINEEND}
     * @return string
     */
    public function generateMessage($EOL = Mime::LINEEND)
    {
        if (! $this->isMultiPart()) {
            $body = array_shift($this->parts);
            $body = $body->getContent($EOL);
        } else {
            $mime = $this->getMime();

            $boundaryLine = $mime->boundaryLine($EOL);
            $body = 'This is a message in Mime Format.  If you see this, '
                  . "your mail reader does not support this format." . $EOL;

            foreach (array_keys($this->parts) as $p) {
                $body .= $boundaryLine
                       . $this->getPartHeaders($p, $EOL)
                       . $EOL
                       . $this->getPartContent($p, $EOL);
            }

            $body .= $mime->mimeEnd($EOL);
        }

        return trim($body);
    }

    /**
     * Get the headers of a given part as an array
     *
     * @param int $partnum
     * @return array
     */
    public function getPartHeadersArray($partnum)
    {
        return $this->parts[$partnum]->getHeadersArray();
    }

    /**
     * Get the headers of a given part as a string
     *
     * @param int $partnum
     * @param string $EOL
     * @return string
     */
    public function getPartHeaders($partnum, $EOL = Mime::LINEEND)
    {
        return $this->parts[$partnum]->getHeaders($EOL);
    }

    /**
     * Get the (encoded) content of a given part as a string
     *
     * @param int $partnum
     * @param string $EOL
     * @return string
     */
    public function getPartContent($partnum, $EOL = Mime::LINEEND)
    {
        return $this->parts[$partnum]->getContent($EOL);
    }

    /**
     * Explode MIME multipart string into separate parts
     *
     * Parts consist of the header and the body of each MIME part.
     *
     * @param string $body
     * @param string $boundary
     * @throws Exception\RuntimeException
     * @return array
     */
    protected static function _disassembleMime($body, $boundary)
    {
        $start = 0;
        $res = array();
        // find every mime part limiter and cut out the
        // string before it.
        // the part before the first boundary string is discarded:
        $p = strpos($body, '--' . $boundary."\n", $start);
        if ($p === false) {
            // no parts found!
            return array();
        }

        // position after first boundary line
        $start = $p + 3 + strlen($boundary);

        while (($p = strpos($body, '--' . $boundary . "\n", $start)) !== false) {
            $res[] = substr($body, $start, $p-$start);
            $start = $p + 3 + strlen($boundary);
        }

        // no more parts, find end boundary
        $p = strpos($body, '--' . $boundary . '--', $start);
        if ($p===false) {
            throw new Exception\RuntimeException('Not a valid Mime Message: End Missing');
        }

        // the remaining part also needs to be parsed:
        $res[] = substr($body, $start, $p-$start);
        return $res;
    }

    /**
     * Decodes a MIME encoded string and returns a Laminas_Mime_Message object with
     * all the MIME parts set according to the given string
     *
     * @param string $message
     * @param string $boundary
     * @param string $EOL EOL string; defaults to {@link Laminas_Mime::LINEEND}
     * @throws Exception\RuntimeException
     * @return \Laminas\Mime\Message
     */
    public static function createFromMessage($message, $boundary, $EOL = Mime::LINEEND)
    {
        $parts = Decode::splitMessageStruct($message, $boundary, $EOL);

        $res = new static();
        foreach ($parts as $part) {
            // now we build a new MimePart for the current Message Part:
            $newPart = new Part($part['body']);
            foreach ($part['header'] as $header) {
                /** @var \Laminas\Mail\Header\HeaderInterface $header */
                /**
                 * @todo check for characterset and filename
                 */

                $fieldName  = $header->getFieldName();
                $fieldValue = $header->getFieldValue();
                switch (strtolower($fieldName)) {
                    case 'content-type':
                        $newPart->type = $fieldValue;
                        break;
                    case 'content-transfer-encoding':
                        $newPart->encoding = $fieldValue;
                        break;
                    case 'content-id':
                        $newPart->id = trim($fieldValue,'<>');
                        break;
                    case 'content-disposition':
                        $newPart->disposition = $fieldValue;
                        break;
                    case 'content-description':
                        $newPart->description = $fieldValue;
                        break;
                    case 'content-location':
                        $newPart->location = $fieldValue;
                        break;
                    case 'content-language':
                        $newPart->language = $fieldValue;
                        break;
                    default:
                        throw new Exception\RuntimeException('Unknown header ignored for MimePart:' . $fieldName);
                }
            }
            $res->addPart($newPart);
        }
        return $res;
    }
}
