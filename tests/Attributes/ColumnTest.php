<?php

namespace Tests\Attributes;

use PHPUnit\Framework\TestCase;
use Neuron\Orm\Attributes\Column;

class ColumnTest extends TestCase
{
	public function test_column_with_all_parameters(): void
	{
		$column = new Column(
			name: 'user_email',
			type: 'string',
			nullable: true
		);

		$this->assertEquals( 'user_email', $column->name );
		$this->assertEquals( 'string', $column->type );
		$this->assertTrue( $column->nullable );
	}

	public function test_column_with_name_only(): void
	{
		$column = new Column( name: 'custom_field' );

		$this->assertEquals( 'custom_field', $column->name );
		$this->assertNull( $column->type );
		$this->assertFalse( $column->nullable );
	}

	public function test_column_with_no_parameters(): void
	{
		$column = new Column();

		$this->assertNull( $column->name );
		$this->assertNull( $column->type );
		$this->assertFalse( $column->nullable );
	}

	public function test_column_with_type_and_nullable(): void
	{
		$column = new Column(
			type: 'datetime',
			nullable: true
		);

		$this->assertNull( $column->name );
		$this->assertEquals( 'datetime', $column->type );
		$this->assertTrue( $column->nullable );
	}

	public function test_column_properties_are_readonly(): void
	{
		$column = new Column( name: 'test' );

		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'Cannot modify readonly property' );

		$column->name = 'changed';
	}
}
