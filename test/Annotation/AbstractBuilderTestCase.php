<?php

declare(strict_types=1);

namespace LaminasTest\Form\Annotation;

use ArrayObject;
use Generator;
use Laminas\Form\Annotation;
use Laminas\Form\Element;
use Laminas\Form\Element\Collection;
use Laminas\Form\Fieldset;
use Laminas\Form\FieldsetInterface;
use Laminas\Hydrator\ClassMethodsHydrator;
use Laminas\Hydrator\ObjectPropertyHydrator;
use Laminas\InputFilter\Input;
use Laminas\InputFilter\InputFilterInterface;
use Laminas\InputFilter\InputInterface;
use Laminas\Stdlib\PriorityList;
use LaminasTest\Form\TestAsset;
use LaminasTest\Form\TestAsset\Annotation\Entity;
use LaminasTest\Form\TestAsset\Annotation\Form;
use LaminasTest\Form\TestAsset\Annotation\InputFilter;
use LaminasTest\Form\TestAsset\Annotation\InputFilterInput;
use PHPUnit\Framework\TestCase;
use Throwable;

use function getenv;

abstract class AbstractBuilderTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('TESTS_LAMINAS_FORM_ANNOTATION_SUPPORT')) {
            $this->markTestSkipped('Enable TESTS_LAMINAS_FORM_ANNOTATION_SUPPORT to test annotation parsing');
        }
    }

    abstract protected function createBuilder(): Annotation\AbstractBuilder;

    public function testCanCreateFormFromStandardEntity(): void
    {
        $entity  = new TestAsset\Annotation\Entity();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        self::assertTrue($form->has('username'));
        self::assertTrue($form->has('password'));

        $username = $form->get('username');
        self::assertInstanceOf(Element::class, $username);
        self::assertEquals('required', $username->getAttribute('required'));

        $password = $form->get('password');
        self::assertInstanceOf(Element::class, $password);
        $attributes = $password->getAttributes();
        self::assertEquals(
            ['type' => 'password', 'label' => 'Enter your password', 'name' => 'password'],
            $attributes
        );
        self::assertNull($password->getAttribute('required'));

        $filter = $form->getInputFilter();
        self::assertTrue($filter->has('username'));
        self::assertTrue($filter->has('password'));

        $username = $filter->get('username');
        self::assertTrue($username->isRequired());
        self::assertCount(1, $username->getFilterChain());
        self::assertCount(2, $username->getValidatorChain());

        $password = $filter->get('password');
        self::assertTrue($password->isRequired());
        self::assertCount(1, $password->getFilterChain());
        self::assertCount(1, $password->getValidatorChain());
    }

    public function testCanCreateFormWithClassAnnotations(): void
    {
        $entity  = new TestAsset\Annotation\ClassEntity();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        self::assertTrue($form->has('keeper'));
        self::assertFalse($form->has('keep'));
        self::assertFalse($form->has('omit'));
        self::assertEquals('some_name', $form->getName());

        $attributes = $form->getAttributes();
        self::assertArrayHasKey('legend', $attributes);
        self::assertEquals('Some Fieldset', $attributes['legend']);

        $filter = $form->getInputFilter();
        self::assertInstanceOf(InputFilter::class, $filter);

        $keeper     = $form->get('keeper');
        $attributes = $keeper->getAttributes();
        self::assertArrayHasKey('type', $attributes);
        self::assertEquals('text', $attributes['type']);

        self::assertInstanceOf(\Laminas\Form\Form::class, $form);
        self::assertSame(['omit', 'keep'], $form->getValidationGroup());
    }

    public function testComplexEntityCreationWithPriorities(): void
    {
        $entity  = new TestAsset\Annotation\ComplexEntity();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        self::assertEquals('user', $form->getName());
        $attributes = $form->getAttributes();
        self::assertArrayHasKey('legend', $attributes);
        self::assertEquals('Register', $attributes['legend']);

        self::assertFalse($form->has('someComposedObject'));
        self::assertTrue($form->has('user_image'));
        self::assertTrue($form->has('email'));
        self::assertTrue($form->has('password'));
        self::assertTrue($form->has('username'));

        $email       = $form->get('email');
        $traversable = $form->getIterator();
        self::assertInstanceOf(PriorityList::class, $traversable);
        $test = $traversable->getIterator()->current();
        self::assertSame($email, $test, 'Test is element ' . $test->getName());

        $test = $traversable->current();
        self::assertSame($email, $test, 'Test is element ' . $test->getName());

        $hydrator = $form->getHydrator();
        self::assertInstanceOf(ObjectPropertyHydrator::class, $hydrator);
    }

    public function testFieldsetOrder(): void
    {
        $entity  = new TestAsset\Annotation\FieldsetOrderEntity();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        $element     = $form->get('element');
        $traversable = $form->getIterator();
        self::assertInstanceOf(PriorityList::class, $traversable);
        $first = $traversable->getIterator()->current();
        self::assertSame($element, $first, 'Test is element ' . $first->getName());
    }

    public function testFieldsetOrderWithPreserve(): void
    {
        $entity  = new TestAsset\Annotation\FieldsetOrderEntity();
        $builder = $this->createBuilder();
        $builder->setPreserveDefinedOrder(true);
        $form = $builder->createForm($entity);

        $fieldset    = $form->get('fieldset');
        $traversable = $form->getIterator();
        self::assertInstanceOf(PriorityList::class, $traversable);
        $first = $traversable->getIterator()->current();
        self::assertSame($fieldset, $first, 'Test is element ' . $first->getName());
    }

    public function testCanRetrieveOnlyFormSpecification(): void
    {
        $entity  = new TestAsset\Annotation\ComplexEntity();
        $builder = $this->createBuilder();
        $spec    = $builder->getFormSpecification($entity);
        self::assertInstanceOf(ArrayObject::class, $spec);
    }

    public function testAllowsExtensionOfEntities(): void
    {
        $entity  = new TestAsset\Annotation\ExtendedEntity();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        self::assertTrue($form->has('username'));
        self::assertTrue($form->has('password'));
        self::assertTrue($form->has('email'));

        self::assertEquals('extended', $form->getName());
        $expected = ['username', 'password', 'email'];
        $test     = [];
        foreach ($form as $element) {
            $test[] = $element->getName();
        }
        self::assertEquals($expected, $test);
    }

    public function testAllowsSpecifyingFormAndElementTypes(): void
    {
        $entity  = new TestAsset\Annotation\TypedEntity();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        self::assertInstanceOf(Form::class, $form);
        $element = $form->get('typed_element');
        self::assertInstanceOf(\LaminasTest\Form\TestAsset\Annotation\Element::class, $element);
    }

    public function testAllowsComposingChildEntities(): void
    {
        $entity  = new TestAsset\Annotation\EntityComposingAnEntity();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        self::assertTrue($form->has('composed'));
        $composed = $form->get('composed');
        self::assertInstanceOf(FieldsetInterface::class, $composed);
        self::assertTrue($composed->has('username'));
        self::assertTrue($composed->has('password'));

        $filter = $form->getInputFilter();
        self::assertTrue($filter->has('composed'));
        $composed = $filter->get('composed');
        self::assertInstanceOf(InputFilterInterface::class, $composed);
        self::assertTrue($composed->has('username'));
        self::assertTrue($composed->has('password'));
    }

    public function testAllowsComposingMultipleChildEntities(): void
    {
        $entity  = new TestAsset\Annotation\EntityComposingMultipleEntities();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        self::assertTrue($form->has('composed'));
        $composed = $form->get('composed');

        self::assertInstanceOf(Collection::class, $composed);
        $target = $composed->getTargetElement();
        self::assertInstanceOf(FieldsetInterface::class, $target);
        self::assertTrue($target->has('username'));
        self::assertTrue($target->has('password'));
    }

    /**
     * @dataProvider provideOptionsAnnotationAndComposedObjectAnnotation
     * @group issue-7108
     */
    public function testOptionsAnnotationAndComposedObjectAnnotation(string $childName): void
    {
        $entity  = new TestAsset\Annotation\EntityUsingComposedObjectAndOptions();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        $child = $form->get($childName);

        self::assertInstanceOf(Collection::class, $child);
        $target = $child->getTargetElement();
        self::assertInstanceOf(FieldsetInterface::class, $target);
        self::assertEquals('My label', $child->getLabel());
    }

    /**
     * Data provider
     *
     * @return Generator
     */
    public function provideOptionsAnnotationAndComposedObjectAnnotation()
    {
        yield ['child'];
        yield ['childTheSecond'];
    }

    /**
     * @dataProvider provideOptionsAnnotationAndComposedObjectAnnotationNoneCollection
     * @group issue-7108
     */
    public function testOptionsAnnotationAndComposedObjectAnnotationNoneCollection(string $childName): void
    {
        $entity  = new TestAsset\Annotation\EntityUsingComposedObjectAndOptions();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        $child = $form->get($childName);

        self::assertInstanceOf(FieldsetInterface::class, $child);
        self::assertEquals('My label', $child->getLabel());
    }

    /**
     * Data provider
     *
     * @return Generator
     */
    public function provideOptionsAnnotationAndComposedObjectAnnotationNoneCollection()
    {
        yield ['childTheThird'];
        yield ['childTheFourth'];
    }

    public function testCanHandleOptionsAnnotation(): void
    {
        $entity  = new TestAsset\Annotation\EntityUsingOptions();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        self::assertInstanceOf(Fieldset::class, $form);
        self::assertTrue($form->useAsBaseFieldset());

        self::assertTrue($form->has('username'));

        $username = $form->get('username');
        self::assertInstanceOf(Element::class, $username);

        self::assertEquals('Username:', $username->getLabel());
        self::assertEquals(['class' => 'label'], $username->getLabelAttributes());
    }

    public function testCanHandleHydratorArrayAnnotation(): void
    {
        $entity  = new TestAsset\Annotation\EntityWithHydratorArray();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        $hydrator = $form->getHydrator();
        self::assertInstanceOf(ClassMethodsHydrator::class, $hydrator);
        self::assertFalse($hydrator->getUnderscoreSeparatedKeys());
    }

    public function testAllowTypeAsElementNameInInputFilter(): void
    {
        $entity  = new TestAsset\Annotation\EntityWithTypeAsElementName();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        self::assertInstanceOf(\Laminas\Form\Form::class, $form);
        $element = $form->get('type');
        self::assertInstanceOf(Element::class, $element);
    }

    public function testAllowEmptyInput(): void
    {
        $entity  = new TestAsset\Annotation\SampleEntity();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        $inputFilter = $form->getInputFilter();
        $sampleinput = $inputFilter->get('sampleinput');
        self::assertTrue($sampleinput->allowEmpty());
    }

    public function testContinueIfEmptyInput(): void
    {
        $entity  = new TestAsset\Annotation\SampleEntity();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        $inputFilter = $form->getInputFilter();
        $sampleinput = $inputFilter->get('sampleinput');
        self::assertInstanceOf(Input::class, $sampleinput);
        self::assertTrue($sampleinput->continueIfEmpty());
    }

    public function testInputNotRequiredByDefault(): void
    {
        $entity      = new TestAsset\Annotation\SampleEntity();
        $builder     = $this->createBuilder();
        $form        = $builder->createForm($entity);
        $inputFilter = $form->getInputFilter();
        $sampleinput = $inputFilter->get('anotherSampleInput');
        self::assertFalse($sampleinput->isRequired());
    }

    public function testInstanceElementAnnotation(): void
    {
        $entity  = new TestAsset\Annotation\EntityUsingInstanceProperty();
        $builder = $this->createBuilder();
        $form    = $builder->createForm($entity);

        $fieldset = $form->get('object');

        self::assertInstanceOf(Fieldset::class, $fieldset);
        self::assertInstanceOf(Entity::class, $fieldset->getObject());
        $hydrator = $fieldset->getHydrator();
        self::assertInstanceOf(ClassMethodsHydrator::class, $hydrator);
        self::assertFalse($hydrator->getUnderscoreSeparatedKeys());
    }

    public function testInputFilterInputAnnotation(): void
    {
        $entity      = new TestAsset\Annotation\EntityWithInputFilterInput();
        $builder     = $this->createBuilder();
        $form        = $builder->createForm($entity);
        $inputFilter = $form->getInputFilter();

        self::assertTrue($inputFilter->has('input'));
        $expected = [
            InputInterface::class,
            InputFilterInput::class,
        ];
        foreach ($expected as $expectedInstance) {
            self::assertInstanceOf($expectedInstance, $inputFilter->get('input'));
        }
    }

    /**
     * @group issue-6753
     */
    public function testInputFilterAnnotationAllowsComposition(): void
    {
        $entity      = new TestAsset\Annotation\EntityWithInputFilterAnnotation();
        $builder     = $this->createBuilder();
        $form        = $builder->createForm($entity);
        $inputFilter = $form->getInputFilter();
        self::assertCount(2, $inputFilter->get('username')->getValidatorChain());
    }

    public function testLegacyComposedObjectAnnotation(): void
    {
        try {
            $entity  = new TestAsset\Annotation\LegacyComposedObjectAnnotation();
            $builder = $this->createBuilder();
            $builder->createForm($entity);
            self::fail('Neither a deprecation nor an exception were thrown');
        } catch (Throwable $error) {
            self::assertMatchesRegularExpression('/Passing a single array .* is deprecated/', $error->getMessage());
        }
    }

    public function testLegacyStyleFilterAnnotations(): void
    {
        try {
            $entity  = new TestAsset\Annotation\LegacyFilterAnnotation();
            $builder = $this->createBuilder();
            $builder->createForm($entity);
            self::fail('Neither a deprecation nor an exception were thrown');
        } catch (Throwable $error) {
            self::assertMatchesRegularExpression('/Passing a single array .* is deprecated/', $error->getMessage());
        }
    }

    public function testLegacyStyleHydratorAnnotations(): void
    {
        try {
            $entity  = new TestAsset\Annotation\LegacyHydratorAnnotation();
            $builder = $this->createBuilder();
            $builder->createForm($entity);
            self::fail('Neither a deprecation nor an exception were thrown');
        } catch (Throwable $error) {
            self::assertMatchesRegularExpression('/Passing a single array .* is deprecated/', $error->getMessage());
        }
    }

    public function testLegacyStyleValidatorAnnotations(): void
    {
        try {
            $entity  = new TestAsset\Annotation\LegacyValidatorAnnotation();
            $builder = $this->createBuilder();
            $builder->createForm($entity);
            self::fail('Neither a deprecation nor an exception were thrown');
        } catch (Throwable $error) {
            self::assertMatchesRegularExpression('/Passing a single array .* is deprecated/', $error->getMessage());
        }
    }
}
