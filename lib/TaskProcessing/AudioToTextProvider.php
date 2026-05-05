<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\AudioTranscriptionPreparationService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AudioToTextProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private AudioTranscriptionPreparationService $audioTranscriptionPreparationService,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-audio2text';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return AudioToText::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [
			'language' => new ShapeDescriptor(
			$this->l->t('Language'),
			$this->l->t('The language of the audio file'),
			EShapeType::Enum
			),
			'output_format' => new ShapeDescriptor(
				$this->l->t('Output format'),
				$this->l->t('The format of the transcription output'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		$languageEnumValues = array_map(static function (array $language) {
			return new ShapeEnumValue($language[1], $language[0]);
		}, Application::AUDIO_TO_TEXT_LANGUAGES);
		$detectLanguageEnumValue = new ShapeEnumValue($this->l->t('Detect language'), 'detect_language');
		$defaultLanguageEnumValue = new ShapeEnumValue($this->l->t('Default'), 'default');
		return [
			'language' => array_merge([$detectLanguageEnumValue, $defaultLanguageEnumValue], $languageEnumValues),
			'output_format' => [
				new ShapeEnumValue($this->l->t('Plain text'), 'text'),
				new ShapeEnumValue($this->l->t('Timestamped segments'), 'segments_json'),
			],
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		return ['language' => 'default', 'output_format' => 'text'];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if (!isset($input['input']) || !$input['input'] instanceof File || !$input['input']->isReadable()) {
			throw new RuntimeException('Invalid input file');
		}
		$inputFile = $input['input'];
		$language = $input['language'] ?? 'default';
		if (!is_string($language)) {
			throw new RuntimeException('Invalid language');
		}
		$outputFormat = $input['output_format'] ?? 'text';
		if (!is_string($outputFormat) || !in_array($outputFormat, ['text', 'segments_json'], true)) {
			throw new RuntimeException('Invalid output format');
		}

		$model = $this->appConfig->getValueString(Application::APP_ID, 'default_stt_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID;
		$preparedFiles = [];

		try {
			$preparedFiles = $this->audioTranscriptionPreparationService->prepare($inputFile);
			$transcriptions = [];

			foreach ($preparedFiles as $preparedFile) {
				if ($outputFormat === 'segments_json') {
					$transcriptions[] = $this->openAiAPIService->transcribeLocalFileWithSegments(
						$userId,
						$preparedFile['path'],
						$preparedFile['filename'],
						false,
						$model,
						$language,
					);
				} else {
					$transcriptions[] = $this->openAiAPIService->transcribeLocalFile(
						$userId,
						$preparedFile['path'],
						$preparedFile['filename'],
						false,
						$model,
						$language,
					);
				}
			}

			if ($outputFormat === 'segments_json') {
				return ['output' => json_encode($this->mergeSegmentedTranscriptions($transcriptions), JSON_THROW_ON_ERROR)];
			}

			return ['output' => implode("\n\n", $transcriptions)];
		} catch (Exception $e) {
			$this->logger->warning('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new RuntimeException('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage());
		} finally {
			$this->audioTranscriptionPreparationService->cleanup($preparedFiles);
		}
	}

	/**
	 * @param list<array{text: string, segments?: list<array<string, mixed>>}> $transcriptions
	 * @return array{text: string, segments: list<array<string, mixed>>}
	 */
	private function mergeSegmentedTranscriptions(array $transcriptions): array {
		$text = [];
		$segments = [];
		$offset = 0.0;

		foreach ($transcriptions as $transcription) {
			$text[] = $transcription['text'];
			$lastEnd = 0.0;

			foreach ($transcription['segments'] ?? [] as $segment) {
				if (isset($segment['start']) && is_numeric($segment['start'])) {
					$segment['start'] = (float)$segment['start'] + $offset;
				}
				if (isset($segment['end']) && is_numeric($segment['end'])) {
					$segment['end'] = (float)$segment['end'] + $offset;
					$lastEnd = max($lastEnd, (float)$segment['end']);
				}
				$segments[] = $segment;
			}

			$offset = $lastEnd;
		}

		return [
			'text' => implode("\n\n", $text),
			'segments' => $segments,
		];
	}
}
