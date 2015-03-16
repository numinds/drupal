<?php

/**
 * @file
 * Contains \Drupal\Tests\Core\Render\RendererTest.
 */

namespace Drupal\Tests\Core\Render;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Render\Element;
use Drupal\Core\Template\Attribute;

/**
 * @coversDefaultClass \Drupal\Core\Render\Renderer
 * @group Render
 */
class RendererTest extends RendererTestBase {

  protected $defaultThemeVars = [
    '#cache' => [
      'contexts' => [],
      'tags' => [],
      'max-age' => Cache::PERMANENT,
    ],
    '#attached' => [],
    '#post_render_cache' => [],
    '#children' => '',
  ];

  /**
   * @covers ::render
   * @covers ::doRender
   *
   * @dataProvider providerTestRenderBasic
   */
  public function testRenderBasic($build, $expected, callable $setup_code = NULL) {
    if (isset($setup_code)) {
      $setup_code = $setup_code->bindTo($this);
      $setup_code();
    }

    $this->assertSame($expected, $this->renderer->render($build));
  }

  /**
   * Provides a list of render arrays to test basic rendering.
   *
   * @return array
   */
  public function providerTestRenderBasic() {
    $data = [];


    // Part 1: the most simplistic render arrays possible, none using #theme.


    // Pass a NULL.
    $data[] = [NULL, ''];
    // Pass an empty string.
    $data[] = ['', ''];
    // Previously printed, see ::renderTwice for a more integration-like test.
    $data[] = [[
      '#markup' => 'foo',
      '#printed' => TRUE,
    ], ''];
    // Printed in pre_render.
    $data[] = [[
      '#markup' => 'foo',
      '#pre_render' => [[new TestCallables(), 'preRenderPrinted']]
    ], ''];
    // Basic #markup based renderable array.
    $data[] = [[
      '#markup' => 'foo',
    ], 'foo'];
    // Renderable child element.
    $data[] = [[
      'child' => ['#markup' => 'bar'],
    ], 'bar'];
    // #children set but empty, and renderable children.
    $data[] = [[
      '#children' => '',
      'child' => ['#markup' => 'bar'],
    ], 'bar'];
    // #children set, not empty, and renderable children. #children will be
    // assumed oto be the rendered child elements, even though the #markup for
    // 'child' differs.
    $data[] = [[
      '#children' => 'foo',
      'child' => ['#markup' => 'bar'],
    ], 'foo'];

    // Part 2: render arrays using #theme and #theme_wrappers.


    // Tests that #theme and #theme_wrappers can co-exist on an element.
    $build = [
      '#theme' => 'common_test_foo',
      '#foo' => 'foo',
      '#bar' => 'bar',
      '#theme_wrappers' => ['container'],
      '#attributes' => ['class' => ['baz']],
    ];
    $setup_code_type_link = function() {
      $this->setupThemeContainer();
      $this->themeManager->expects($this->at(0))
        ->method('render')
        ->with('common_test_foo', $this->anything())
        ->willReturnCallback(function($theme, $vars) {
          return $vars['#foo'] . $vars['#bar'];
        });
    };
    $data[] = [$build, '<div class="baz">foobar</div>' . "\n", $setup_code_type_link];

    // Tests that #theme_wrappers can disambiguate element attributes shared
    // with rendering methods that build #children by using the alternate
    // #theme_wrappers attribute override syntax.
    $build = [
      '#type' => 'link',
      '#theme_wrappers' => [
        'container' => [
          '#attributes' => ['class' => ['baz']],
        ],
      ],
      '#attributes' => ['id' => 'foo'],
      '#url' => 'http://drupal.org',
      '#title' => 'bar',
    ];
    $setup_code_type_link = function() {
      $this->setupThemeContainer();
      $this->themeManager->expects($this->at(0))
        ->method('render')
        ->with('link', $this->anything())
        ->willReturnCallback(function($theme, $vars) {
          $attributes = new Attribute(['href' => $vars['#url']] + (isset($vars['#attributes']) ? $vars['#attributes'] : []));
          return '<a' . (string) $attributes . '>' . $vars['#title'] . '</a>';
        });
      $this->elementInfo->expects($this->atLeastOnce())
        ->method('getInfo')
        ->with('link')
        ->willReturn(['#theme' => 'link']);
    };
    $data[] = [$build, '<div class="baz"><a href="http://drupal.org" id="foo">bar</a></div>' . "\n", $setup_code_type_link];

    // Tests that #theme_wrappers can disambiguate element attributes when the
    // "base" attribute is not set for #theme.
    $build = [
      '#type' => 'link',
      '#url' => 'http://drupal.org',
      '#title' => 'foo',
      '#theme_wrappers' => [
        'container' => [
          '#attributes' => ['class' => ['baz']],
        ],
      ],
    ];
    $data[] = [$build, '<div class="baz"><a href="http://drupal.org">foo</a></div>' . "\n", $setup_code_type_link];

    // Tests two 'container' #theme_wrappers, one using the "base" attributes
    // and one using an override.
    $build = [
      '#attributes' => ['class' => ['foo']],
      '#theme_wrappers' => [
        'container' => [
          '#attributes' => ['class' => ['bar']],
        ],
        'container',
      ],
    ];
    $setup_code = function() {
      $this->setupThemeContainer($this->any());
    };
    $data[] = [$build, '<div class="foo"><div class="bar"></div>' . "\n" . '</div>' . "\n", $setup_code];

    // Tests array syntax theme hook suggestion in #theme_wrappers.
    $build = [
      '#theme_wrappers' => [['container']],
      '#attributes' => ['class' => ['foo']],
    ];
    $setup_code = function() {
      $this->setupThemeContainerMultiSuggestion($this->any());
    };
    $data[] = [$build, '<div class="foo"></div>' . "\n", $setup_code];


    // Part 3: render arrays using #markup as a fallback for #theme hooks.


    // Theme suggestion is not implemented, #markup should be rendered.
    $build = [
      '#theme' => ['suggestionnotimplemented'],
      '#markup' => 'foo',
    ];
    $setup_code = function() {
      $this->themeManager->expects($this->once())
        ->method('render')
        ->with(['suggestionnotimplemented'], $this->anything())
        ->willReturn(FALSE);
    };
    $data[] = [$build, 'foo', $setup_code];

    // Tests unimplemented theme suggestion, child #markup should be rendered.
    $build = [
      '#theme' => ['suggestionnotimplemented'],
      'child' => [
        '#markup' => 'foo',
      ],
    ];
    $setup_code = function() {
      $this->themeManager->expects($this->once())
        ->method('render')
        ->with(['suggestionnotimplemented'], $this->anything())
        ->willReturn(FALSE);
    };
    $data[] = [$build, 'foo', $setup_code];

    // Tests implemented theme suggestion: #markup should not be rendered.
    $build = [
      '#theme' => ['common_test_empty'],
      '#markup' => 'foo',
    ];
    $theme_function_output = $this->randomContextValue();
    $setup_code = function() use ($theme_function_output) {
      $this->themeManager->expects($this->once())
        ->method('render')
        ->with(['common_test_empty'], $this->anything())
        ->willReturn($theme_function_output);
    };
    $data[] = [$build, $theme_function_output, $setup_code];

    // Tests implemented theme suggestion: children should not be rendered.
    $build = [
      '#theme' => ['common_test_empty'],
      'child' => [
        '#markup' => 'foo',
      ],
    ];
    $data[] = [$build, $theme_function_output, $setup_code];


    // Part 4: handling of #children and child renderable elements.


    // #theme is implemented so the values of both #children and 'child' will
    // be ignored - it is the responsibility of the theme hook to render these
    // if appropriate.
    $build = [
      '#theme' => 'common_test_foo',
      '#children' => 'baz',
      'child' => ['#markup' => 'boo'],
    ];
    $setup_code = function() {
      $this->themeManager->expects($this->once())
        ->method('render')
        ->with('common_test_foo', $this->anything())
        ->willReturn('foobar');
    };
    $data[] = [$build, 'foobar', $setup_code];

    // #theme is implemented but #render_children is TRUE. As in the case where
    // #theme is not set, empty #children means child elements are rendered
    // recursively.
    $build = [
      '#theme' => 'common_test_foo',
      '#children' => '',
      '#render_children' => TRUE,
      'child' => [
        '#markup' => 'boo',
      ],
    ];
    $setup_code = function() {
      $this->themeManager->expects($this->never())
        ->method('render');
    };
    $data[] = [$build, 'boo', $setup_code];

    // #theme is implemented but #render_children is TRUE. As in the case where
    // #theme is not set, #children will take precedence over 'child'.
    $build = [
      '#theme' => 'common_test_foo',
      '#children' => 'baz',
      '#render_children' => TRUE,
      'child' => [
        '#markup' => 'boo',
      ],
    ];
    $setup_code = function() {
      $this->themeManager->expects($this->never())
        ->method('render');
    };
    $data[] = [$build, 'baz', $setup_code];

    return $data;
  }

  /**
   * @covers ::render
   * @covers ::doRender
   */
  public function testRenderSorting() {
    $first = $this->randomMachineName();
    $second = $this->randomMachineName();
    // Build an array with '#weight' set for each element.
    $elements = [
      'second' => [
        '#weight' => 10,
        '#markup' => $second,
      ],
      'first' => [
        '#weight' => 0,
        '#markup' => $first,
      ],
    ];
    $output = $this->renderer->render($elements);

    // The lowest weight element should appear last in $output.
    $this->assertTrue(strpos($output, $second) > strpos($output, $first), 'Elements were sorted correctly by weight.');

    // Confirm that the $elements array has '#sorted' set to TRUE.
    $this->assertTrue($elements['#sorted'], "'#sorted' => TRUE was added to the array");

    // Pass $elements through \Drupal\Core\Render\Element::children() and
    // ensure it remains sorted in the correct order. drupal_render() will
    // return an empty string if used on the same array in the same request.
    $children = Element::children($elements);
    $this->assertTrue(array_shift($children) == 'first', 'Child found in the correct order.');
    $this->assertTrue(array_shift($children) == 'second', 'Child found in the correct order.');
  }

  /**
   * @covers ::render
   * @covers ::doRender
   */
  public function testRenderSortingWithSetHashSorted() {
    $first = $this->randomMachineName();
    $second = $this->randomMachineName();
    // The same array structure again, but with #sorted set to TRUE.
    $elements = array(
      'second' => array(
        '#weight' => 10,
        '#markup' => $second,
      ),
      'first' => array(
        '#weight' => 0,
        '#markup' => $first,
      ),
      '#sorted' => TRUE,
    );
    $output = $this->renderer->render($elements);

    // The elements should appear in output in the same order as the array.
    $this->assertTrue(strpos($output, $second) < strpos($output, $first), 'Elements were not sorted.');
  }

  /**
   * @covers ::render
   * @covers ::doRender
   *
   * @dataProvider providerBoolean
   */
  public function testRenderWithPresetAccess($access) {
    $build = [
      '#access' => $access,
    ];

    $this->assertAccess($build, $access);
  }

  /**
   * @covers ::render
   * @covers ::doRender
   *
   * @dataProvider providerBoolean
   */
  public function testRenderWithAccessCallbackCallable($access) {
    $build = [
      '#access_callback' => function() use ($access) {
        return $access;
      }
    ];

    $this->assertAccess($build, $access);
  }

  /**
   * Ensures that the #access property wins over the callable.
   *
   * @covers ::render
   * @covers ::doRender
   *
   * @dataProvider providerBoolean
   */
  public function testRenderWithAccessPropertyAndCallback($access) {
    $build = [
      '#access' => $access,
      '#access_callback' => function() {
        return TRUE;
      }
    ];

    $this->assertAccess($build, $access);
  }

  /**
   * @covers ::render
   * @covers ::doRender
   *
   * @dataProvider providerBoolean
   */
  public function testRenderWithAccessControllerResolved($access) {
    $build = [
      '#access_callback' => 'Drupal\Tests\Core\Render\TestAccessClass::' . ($access ? 'accessTrue' : 'accessFalse'),
    ];

    $this->assertAccess($build, $access);
  }

  /**
   * Tests that a first render returns the rendered output and a second doesn't.
   *
   * (Because of the #printed property.)
   *
   * @covers ::render
   * @covers ::doRender
   */
  public function testRenderTwice() {
    $build = [
      '#markup' => 'test',
    ];

    $this->assertEquals('test', $this->renderer->render($build));
    $this->assertTrue($build['#printed']);

    // We don't want to reprint already printed render arrays.
    $this->assertEquals('', $this->renderer->render($build));
  }

  /**
   * Provides a list of both booleans.
   *
   * @return array
   */
  public function providerBoolean() {
    return [
      [FALSE],
      [TRUE]
    ];
  }

  /**
   * Asserts that a render array with access checking renders correctly.
   *
   * @param array $build
   *   A render array with either #access or #access_callback.
   * @param bool $access
   *   Whether the render array is accessible or not.
   */
  protected function assertAccess($build, $access) {
    $sensitive_content = $this->randomContextValue();
    $build['#markup'] = $sensitive_content;
    if ($access) {
      $this->assertSame($sensitive_content, $this->renderer->render($build));
    }
    else {
      $this->assertSame('', $this->renderer->render($build));
    }
  }

  protected function setupThemeContainer($matcher = NULL) {
    $this->themeManager->expects($matcher ?: $this->at(1))
      ->method('render')
      ->with('container', $this->anything())
      ->willReturnCallback(function($theme, $vars) {
        return '<div' . (string) (new Attribute($vars['#attributes'])) . '>' . $vars['#children'] . "</div>\n";
      });
  }

  protected function setupThemeContainerMultiSuggestion($matcher = NULL) {
    $this->themeManager->expects($matcher ?: $this->at(1))
      ->method('render')
      ->with(['container'], $this->anything())
      ->willReturnCallback(function($theme, $vars) {
        return '<div' . (string) (new Attribute($vars['#attributes'])) . '>' . $vars['#children'] . "</div>\n";
      });
  }

  /**
   * @covers ::render
   * @covers ::doRender
   */
  public function testRenderWithoutThemeArguments() {
    $element = array(
      '#theme' => 'common_test_foo',
    );

    $this->themeManager->expects($this->once())
      ->method('render')
      ->with('common_test_foo', $this->defaultThemeVars + $element)
      ->willReturn('foobar');

    // Test that defaults work.
    $this->assertEquals($this->renderer->render($element), 'foobar', 'Defaults work');
  }

  /**
   * @covers ::render
   * @covers ::doRender
   */
  public function testRenderWithThemeArguments() {
    $element = array(
      '#theme' => 'common_test_foo',
      '#foo' => $this->randomMachineName(),
      '#bar' => $this->randomMachineName(),
    );

    $this->themeManager->expects($this->once())
      ->method('render')
      ->with('common_test_foo', $this->defaultThemeVars + $element)
      ->willReturnCallback(function ($hook, $vars) {
        return $vars['#foo'] . $vars['#bar'];
      });

    // Tests that passing arguments to the theme function works.
    $this->assertEquals($this->renderer->render($element), $element['#foo'] . $element['#bar'], 'Passing arguments to theme functions works');
  }

  /**
   * @covers ::render
   * @covers ::doRender
   * @covers ::cacheGet
   * @covers ::cacheSet
   * @covers ::createCacheID
   */
  public function testRenderCache() {
    $this->setUpRequest();
    $this->setupMemoryCache();

    // Create an empty element.
    $test_element = [
      '#cache' => [
        'cid' => 'render_cache_test',
        'tags' => ['render_cache_tag'],
      ],
      '#markup' => '',
      'child' => [
        '#cache' => [
          'cid' => 'render_cache_test_child',
          'tags' => ['render_cache_tag_child:1', 'render_cache_tag_child:2'],
        ],
        '#markup' => '',
      ],
    ];

    // Render the element and confirm that it goes through the rendering
    // process (which will set $element['#printed']).
    $element = $test_element;
    $this->renderer->render($element);
    $this->assertTrue(isset($element['#printed']), 'No cache hit');

    // Render the element again and confirm that it is retrieved from the cache
    // instead (so $element['#printed'] will not be set).
    $element = $test_element;
    $this->renderer->render($element);
    $this->assertFalse(isset($element['#printed']), 'Cache hit');

    // Test that cache tags are correctly collected from the render element,
    // including the ones from its subchild.
    $expected_tags = [
      'render_cache_tag',
      'render_cache_tag_child:1',
      'render_cache_tag_child:2',
    ];
    $this->assertEquals($expected_tags, $element['#cache']['tags'], 'Cache tags were collected from the element and its subchild.');

    // The cache item also has a 'rendered' cache tag.
    $cache_item = $this->cacheFactory->get('render')->get('render_cache_test');
    $this->assertSame(Cache::mergeTags($expected_tags, ['rendered']), $cache_item->tags);
  }

}

class TestAccessClass {

  public static function accessTrue() {
    return TRUE;
  }

  public static function accessFalse() {
    return FALSE;
  }

}

class TestCallables {

  public function preRenderPrinted($elements) {
    $elements['#printed'] = TRUE;
    return $elements;
  }

}
