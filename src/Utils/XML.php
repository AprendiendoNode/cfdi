<?php

namespace Gmlo\CFDI\Utils;

class XML {

	protected $xml;
	protected $certs_path;
	protected $data;

	public function __construct($certs_path = null)
    {
    	$this->certs_path = $certs_path;
    }

    // Load a xmlo object
    public function loadXML($xml)
    {
    	$this->xml->loadXML($xml);
        if( config('app.env') == 'local' )
    	   $this->xml->save(storage_path('app/cfdi_tmp.xml'));
    }
    // Return xml
   	public function getXML()
   	{
   		return $this->xml;
   	}

   	/**
   	* Generate a XML with data sent
   	*/
    public function generate($data)
    {
    	$this->data = $data;
    	$this->xml  = new \DOMDocument("1.0","UTF-8");
        $root = $this->xml->createElement("cfdi:Comprobante");
        $root = $this->xml->appendChild($root);
        $this->addAttributes($root, $data->general);

        $transmitter = $this->xml->createElement("cfdi:Emisor");
        $transmitter = $root->appendChild($transmitter);
        $this->addAttributes($transmitter, $data->transmitter);

        $receiver = $this->xml->createElement("cfdi:Receptor");
        $receiver = $root->appendChild($receiver);
        $this->addAttributes($receiver, $data->receiver);

        $concepts = $this->xml->createElement("cfdi:Conceptos");
        $concepts = $root->appendChild($concepts);
        $this->addAttributes($concepts);

        foreach ($data->concepts as $concept)
        {
            $concept_ = $this->xml->createElement("cfdi:Concepto");
            $concept_ = $concepts->appendChild($concept_);
            $taxes    = $concept['taxes'];
            unset($concept['taxes']);
            $this->addAttributes($concept_, $concept);

            $taxes_ = $this->xml->createElement("cfdi:Impuestos");
            $taxes_ = $concept_->appendChild($taxes_);

            $taxes_t = $this->xml->createElement("cfdi:Traslados");
            $taxes_t = $taxes_->appendChild($taxes_t);

            foreach ($taxes['transfers'] as $tax) {
                $tax_ = $this->xml->createElement("cfdi:Traslado");
                $tax_ = $taxes_t->appendChild($tax_);
                $this->addAttributes($tax_, $tax);
            }
        }

        $taxes = $this->xml->createElement("cfdi:Impuestos");
        $taxes = $root->appendChild($taxes);
        $this->addAttributes($taxes);

        $taxes_t = $this->xml->createElement("cfdi:Traslados");
        $taxes_t = $taxes->appendChild($taxes_t);
        $this->addAttributes($taxes_t);

        $tax_transferreds = 0;
        foreach ($data->tax_transferred as $tax)
        {
            $aux = $this->xml->createElement("cfdi:Traslado");
            $aux = $taxes_t->appendChild($aux);
            $this->addAttributes($aux, $tax);
            $tax_transferreds += $tax['Importe'];
        }
        if( $tax_transferreds > 0 )
        {
            $this->addAttributes($taxes, ['TotalImpuestosTrasladados' => $tax_transferreds]);
        }

        $original_string = $this->makeOriginalString();

        $this->preStamp($original_string, $root);

        if( config('app.env') == 'local' )
            $this->xml->save(storage_path('app/cfdi_tmp.xml'));
    }

    function preStamp($original_string, $root)
    {
        $certificado    = $this->data->general['NoCertificado'];
        $file           = $this->certs_path . ".key.pem";

        $pkeyid = openssl_get_privatekey(file_get_contents($file));
        openssl_sign($original_string, $crypttext, $pkeyid, OPENSSL_ALGO_SHA256);
        openssl_free_key($pkeyid);

        $sello = base64_encode($crypttext);
        //$this->xml->setAttribute("sello", $sello);
        $this->addAttributes($root, ['Sello' => $sello]);

        $file           = $this->certs_path . ".cer.pem";
        $datos          = file($file);
        $certificado    = "";
        $carga          = false;
        for ( $i=0; $i<sizeof($datos); $i++ )
        {
            if (strstr($datos[$i],"END CERTIFICATE")) $carga=false;
            if ($carga) $certificado .= trim($datos[$i]);
            if (strstr($datos[$i],"BEGIN CERTIFICATE")) $carga=true;
        }
        //$this->xml->setAttribute("certificado", $certificado);
        $this->addAttributes($root, ['Certificado' => $certificado]);
    }

    protected function makeOriginalString()
    {
        $xml = new \DOMDocument("1.0","UTF-8");
        $xml->loadXML($this->xml->saveXML());

        $xsl    = new \DOMDocument("1.0","UTF-8");
        $xsl->load( __DIR__ . "/../resources/xslt/3.3/cadenaoriginal_3_3.xslt" );
        $proc = new \XSLTProcessor;
        $proc->importStyleSheet($xsl);
        return $proc->transformToXML($xml);
    }


    protected function addAttributes(&$node, $attributes = [])
    {
        foreach ( $attributes as $key => $value )
        {
            //$value = htmlspecialchars($value);
            //$value = htmlspecialchars($value, ENT_QUOTES|ENT_XML1);
            $value = preg_replace('/\s\s+/', ' ', $value);
            $value = trim($value);
            if( strlen($value) > 0 )
            {
                $value = str_replace("|","/",$value);
                //$value = str_replace("'", "\&apos;", $value);
                $value = utf8_encode($value);
                $node->setAttribute($key,$value);
            }
        }
    }
}