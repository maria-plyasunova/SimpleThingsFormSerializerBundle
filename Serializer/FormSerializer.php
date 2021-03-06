<?php
/**
 * SimpleThings FormSerializerBundle
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace SimpleThings\FormSerializerBundle\Serializer;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Form\Exception\UnexpectedTypeException;

class FormSerializer
{
    private $factory;
    private $encoder;
    private $options;

    public function __construct(FormFactoryInterface $factory, EncoderInterface $encoder, SerializerOptions $options = null)
    {
        $this->factory = $factory;
        $this->encoder = $encoder;
        $this->options = $options ?: new SerializerOptions;
    }

    public function serialize($object, $typeBuilder, $format)
    {
        if ($typeBuilder instanceof FormTypeInterface) {
            $form = $this->factory->create($typeBuilder, $object);
        } else if ($typeBuilder instanceof FormBuilderInterface) {
            $form = $typeBuilder->getForm();
            $form->setData($object);
        } else if ($typeBuilder instanceof FormInterface) {
            $form = $typeBuilder;
            if ( ! $form->isBound()) {
                $form->setData($object);
            }
        } else {
            throw new UnexpectedTypeException($typeBuilder, 'FormInterface|FormTypeInterface|FormBuilderInterface');
        }

        $options = $form->getConfig()->getOptions();
        $xmlName = isset($options['serialize_xml_name'])
            ? $options['serialize_xml_name']
            : 'entry';

        if ($form->isBound() && ! $form->isValid()) {
            $data    = $this->serializeFormError($form);
            $xmlName = 'form';
        } else {
            $data = $this->serializeForm($form, $format == 'xml');
        }

        if ($format === 'json' && $this->options->getIncludeRootInJson()) {
            $data = array($xmlName => $data);
        }

        if ($format === 'xml') {
            $appXmlName = $this->options->getApplicationXmlRootName();

            if ($appXmlName && $appXmlName !== $xmlName) {
                $data    = array($xmlName => $data);
                $xmlName = $appXmlName;
            }

            $this->encoder->getEncoder('xml')->setRootNodeName($xmlName);
        }

        return $this->encoder->encode($data, $format);
    }

    private function serializeFormError(FormInterface $form)
    {
        $result = array();

        foreach ($form->getErrors() as $error) {
            $result['error'][] = $error->getMessage();
        }

        foreach ($form->getChildren() as $child) {
            $errors = $this->serializeFormError($child);

            if ($errors) {
                $result['children'][$child->getName()] = $errors;
            }
        }

        return $result;
    }

    private function serializeForm(FormInterface $form, $isXml)
    {
        if ( ! $form->hasChildren()) {
            return $form->getViewData();
        }

        $data = array();
        $namingStrategy = $this->options->getNamingStrategy();

        foreach ($form->getChildren() as $child) {
            $options = $child->getConfig()->getOptions();
            $name    = $options['serialize_name'] ?: $namingStrategy->translateName($child);

            if ($isXml) {
                $name = (!$options['serialize_xml_value'])
                    ? ($options['serialize_xml_attribute'] ? '@' . $name : $name)
                    : '#';
            }

            if ( ! $options['serialize_xml_inline']) {
                $data[$name][$options['serialize_xml_name']] = $this->serializeForm($child, $isXml);
            } else {
                $data[$name] = $this->serializeForm($child, $isXml);
            }
        }

        return $data;
    }
}

