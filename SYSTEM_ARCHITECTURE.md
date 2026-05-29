# Text Analysis Web System - Architecture & Context Document

**Document Purpose:** This document is designed to be read by AI systems to enable complete understanding of the project structure, ML pipeline, and implementation details without prior context.

**Last Updated:** May 19, 2026  
**Project Type:** Laravel-based Sentiment Analysis & NLP Platform with Active Learning  
**Tech Stack:** PHP 8.2, Laravel 12, Python (external NLP backend), MySQL, JavaScript/Tailwind CSS

---

## 1. System Overview

### 1.1 Core Objective

This project is a **web-based text analysis platform** for **Indonesian social media sentiment analysis and aspect-based opinion mining** with integrated **Active Learning** capabilities. The system enables:

- **Sentiment Classification:** Positive, Negative, Neutral classification for Indonesian text
- **Aspect-Based Opinion Extraction:** Identifying specific aspects/topics mentioned in text
- **Topic Modeling:** Discovering latent topics in document collections
- **Active Learning Loop:** Continuous model improvement through human-in-the-loop corrections
- **Multi-Source Data Ingestion:** CSV, Excel, TXT files, and manual text input
- **Real-time Progress Tracking:** Asynchronous job queue processing with polling mechanism
- **Model Evaluation & Metrics:** Accuracy, Precision, Recall, F1-Score, Confusion Matrices

### 1.2 Governing Methodology

The project follows the **CRISP-ML(Q) Framework** (Cross-Industry Standard Process for Machine Learning with Quality):

1. **Business Understanding:** Stakeholder requirements → User analysis tasks
2. **Data Understanding:** File upload/manual input → Data preview & validation
3. **Data Preparation:** Preprocessing (tokenization, stopword removal, stemming) → Feature engineering
4. **Modeling:** ML classification models (trained externally, called via API)
5. **Model Evaluation:** Metrics computation, confusion matrix generation, performance reporting
6. **Deployment:** Web interface for predictions, feedback integration
7. **Feedback Loop:** User corrections → Training data accumulation → Model retraining (external)
8. **Monitoring & Quality:** Analysis logs, performance tracking, error handling

### 1.3 Key Business Features

| Feature | Description | Status |
|---------|-------------|--------|
| **User Authentication** | Breeze auth, email verification, role-based access | ✓ Implemented |
| **Analysis Management** | Create, store, track, delete text analysis jobs | ✓ Implemented |
| **File Processing** | Support CSV, Excel, TXT with configurable column/delimiter selection | ✓ Implemented |
| **Real-Time Progress** | Polling-based async job updates (30 reqs/min rate limit) | ✓ Implemented |
| **Active Learning** | User feedback, training item correction, evaluation metrics | ✓ Implemented |
| **Data Export** | PDF reports, CSV results export | ✓ Implemented |
| **Admin Panel** | Preprocessing config management, stopword lists | ✓ Implemented |
| **Preprocessing Configuration** | Case folding, punctuation removal, stemming, custom stopwords | ✓ Configurable |

---

## 2. Technology Stack & Integrations

### 2.1 Backend Framework

| Component | Version | Purpose |
|-----------|---------|---------|
| **Laravel** | 12.0 | Core web framework |
| **PHP** | 8.2+ | Server-side language |
| **MySQL** | 5.7+ | Relational database |
| **Eloquent ORM** | Latest | Database abstraction & relationships |
| **Laravel Breeze** | 2.3+ | Authentication scaffolding |
| **Laravel Queue** | Latest | Async job processing |
| **Guzzle HTTP** | 7.10 | HTTP client for external APIs |

### 2.2 Frontend Stack

| Component | Version | Purpose |
|-----------|---------|---------|
| **Vite** | 7.0.7 | Build tool & dev server |
| **Tailwind CSS** | 3.1 | Utility-first CSS framework |
| **Alpine.js** | 3.4.2 | Lightweight reactive JavaScript |
| **Chart.js** | 4.5.1 | Data visualization & charts |
| **Axios** | 1.11.0 | AJAX requests & polling |
| **AOS** | 3.0.0-beta.6 | Scroll animations |
| **Swiper** | 12.0.3 | Carousel/slider component |

### 2.3 File & Data Processing

| Component | Version | Purpose |
|-----------|---------|---------|
| **PhpSpreadsheet** | 1.30 | Excel & CSV parsing |
| **Laravel Excel** | 3.1 | Spreadsheet import/export |
| **DOMPDF** | 3.1 | PDF generation for reports |
| **Intervention Image** | 3.11 | Image processing (optional) |

### 2.4 External Service Integrations

#### **Primary: Python NLP API Backend**

```
┌─────────────────────────────────────────────┐
│     Laravel Web Application (This Project)   │
└──────────────────┬──────────────────────────┘
                   │
                   │ HTTP REST API
                   │ (Guzzle HTTP Client)
                   ▼
┌─────────────────────────────────────────────┐
│   External Python NLP Service                │
│   (Port: 8001, configurable)                 │
│                                              │
│   Endpoints:                                 │
│   - /health                 (Health check)  │
│   - /api/preprocess         (Text cleaning) │
│   - /api/analyze/sentiment  (Classification)│
│   - /api/analyze/aspect     (Aspect mining) │
│   - /api/analyze/topic      (Topic model)   │
└─────────────────────────────────────────────┘
```

**Configuration (`.env` or `config/services.php`):**
```php
NLP_API_URL=http://localhost:8001
NLP_API_TIMEOUT=7200          // 2 hours for large datasets
NLP_API_BATCH_SIZE=50         // Texts per request
NLP_API_MAX_TEXTS=100         // Max single request
```

**Connection Details:**
- **Protocol:** HTTP REST (JSON request/response)
- **Authentication:** None (internal service)
- **Error Handling:** Exponential backoff (2min, 5min, 10min intervals)
- **Retry Logic:** 3 attempts per job submission
- **Timeout:** 7200 seconds (for large datasets with topic modeling)

---

## 3. Machine Learning Pipeline & Data Flow

### 3.1 High-Level Data Flow Architecture

```
┌──────────────────────────────────────────────────────────────┐
│ 1. DATA INGESTION (User Input)                               │
│                                                               │
│    ┌─────────────┐    ┌─────────────┐    ┌──────────────┐   │
│    │  File       │    │  Manual     │    │  YouTube     │   │
│    │  Upload     │    │  Text Input │    │  Scraping    │   │
│    │  (CSV/XLS)  │    │  (Textarea) │    │  (Optional)  │   │
│    └──────┬──────┘    └──────┬──────┘    └──────┬───────┘   │
│           │                  │                   │            │
│           └──────────────────┼───────────────────┘            │
│                              │                                │
└──────────────────────────────┼────────────────────────────────┘
                               │
┌──────────────────────────────▼────────────────────────────────┐
│ 2. DATA STORAGE & CONFIGURATION                               │
│                                                               │
│    ┌────────────────────────────────────────────────────┐   │
│    │  TextAnalysis Record:                              │   │
│    │  - title, description, input_type                  │   │
│    │  - raw_data (array of texts)                       │   │
│    │  - file_path, file_name, metadata                  │   │
│    │  - status (pending→processing→completed)           │   │
│    │  - progress (10% → 100%)                           │   │
│    │  - analysis_type (sentiment/aspect/topic/combined) │   │
│    └────────────────────────────────────────────────────┘   │
│                                                               │
│    ┌────────────────────────────────────────────────────┐   │
│    │  PreprocessingConfig:                              │   │
│    │  - case_folding (bool)                             │   │
│    │  - remove_punctuation (bool)                       │   │
│    │  - remove_numbers (bool)                           │   │
│    │  - remove_stopwords (bool)                         │   │
│    │  - stemming (bool)                                 │   │
│    │  - lemmatization (bool)                            │   │
│    │  - custom_stopwords (array)                        │   │
│    └────────────────────────────────────────────────────┘   │
│                                                               │
└──────────────────────────────────────────────────────────────┘
                               │
┌──────────────────────────────▼────────────────────────────────┐
│ 3. ASYNCHRONOUS JOB QUEUE PROCESSING (ProcessTextAnalysis)   │
│                                                               │
│    Job Queue (Laravel Queue System - Sync/Redis/Database)    │
│           │                                                   │
│           ▼                                                   │
│    ┌─────────────────────────────────────────────────┐      │
│    │ ProcessTextAnalysis Job Handler                  │      │
│    │ - Retrieves TextAnalysis record                  │      │
│    │ - Validates input data                           │      │
│    │ - Updates progress (10%, 20%, 30%...)           │      │
│    └──────────────────┬──────────────────────────────┘      │
│                       │                                       │
└───────────────────────┼───────────────────────────────────────┘
                        │
┌───────────────────────▼───────────────────────────────────────┐
│ 4. PREPROCESSING & FEATURE EXTRACTION                         │
│                                                               │
│    NLPApiService::preprocessText()                           │
│    ├─ Sends texts to Python API                              │
│    └─ Receives: tokenized, cleaned, normalized text          │
│                                                               │
│    HTTP Request:                                             │
│    POST /api/preprocess                                      │
│    {                                                          │
│      "texts": [...],                                         │
│      "config": {                                             │
│        "case_folding": true,                                 │
│        "remove_punctuation": true,                           │
│        ...                                                    │
│      }                                                        │
│    }                                                          │
│                                                               │
└──────────────────────────────────────────────────────────────┘
                        │
┌───────────────────────▼───────────────────────────────────────┐
│ 5. MODEL INFERENCE & ANALYSIS                                 │
│                                                               │
│    Based on analysis_type:                                    │
│                                                               │
│    ┌─────────────────────────────────────────────────┐      │
│    │ SENTIMENT ANALYSIS                               │      │
│    │                                                  │      │
│    │ NLPApiService::analyzeSentiment($texts, $config)│      │
│    │ ├─ Batch processing if count > 50 texts        │      │
│    │ ├─ For each batch, call /api/analyze/sentiment │      │
│    │ └─ Returns: predictions[0] = {                 │      │
│    │     "label": "positive|negative|neutral",       │      │
│    │     "confidence": 0.95,                         │      │
│    │     "probabilities": {...}                      │      │
│    │   }                                              │      │
│    └─────────────────────────────────────────────────┘      │
│                                                               │
│    ┌─────────────────────────────────────────────────┐      │
│    │ ASPECT-BASED OPINION EXTRACTION                  │      │
│    │                                                  │      │
│    │ NLPApiService::analyzeAspect($texts, ...)      │      │
│    │ ├─ Mode: rule-based OR automatic                │      │
│    │ ├─ Returns: predictions[0] = {                 │      │
│    │ │   "aspects": ["price", "quality", ...],       │      │
│    │ │   "opinion_words": ["expensive", "good", ...] │      │
│    │ │ }                                              │      │
│    │ └─ Sentiment per aspect (if combined)           │      │
│    └─────────────────────────────────────────────────┘      │
│                                                               │
│    ┌─────────────────────────────────────────────────┐      │
│    │ TOPIC MODELING                                   │      │
│    │                                                  │      │
│    │ NLPApiService::analyzeTopic($texts, ...)       │      │
│    │ ├─ LDA or similar algorithm                      │      │
│    │ ├─ Num topics: metadata['num_topics'] (default 5)│     │
│    │ └─ Returns: {                                    │      │
│    │     "topics": [                                  │      │
│    │       {"id": 0, "words": ["price", "cost", ...]},│     │
│    │       ...                                         │      │
│    │     ],                                            │      │
│    │     "document_topics": [...]                     │      │
│    │   }                                              │      │
│    └─────────────────────────────────────────────────┘      │
│                                                               │
│    ┌─────────────────────────────────────────────────┐      │
│    │ COMBINED ANALYSIS                                │      │
│    │                                                  │      │
│    │ Runs sentiment + aspect + topic sequentially    │      │
│    └─────────────────────────────────────────────────┘      │
│                                                               │
└──────────────────────────────────────────────────────────────┘
                        │
┌───────────────────────▼───────────────────────────────────────┐
│ 6. RESULT AGGREGATION & STORAGE                               │
│                                                               │
│    ┌────────────────────────────────────────────────┐       │
│    │ AnalysisResult Record:                         │       │
│    │ - preprocessed_data (array)                     │       │
│    │ - predictions (array of individual results)     │       │
│    │ - sentiment_distribution:                       │       │
│    │   {                                             │       │
│    │     "positive": 35,                             │       │
│    │     "negative": 20,                             │       │
│    │     "neutral": 45                               │       │
│    │   }                                             │       │
│    │ - aspect_results (aspects & sentiments)         │       │
│    │ - topic_results (topics & distributions)        │       │
│    │ - metrics (auto-calculated):                    │       │
│    │   {                                             │       │
│    │     "total_texts": 100,                         │       │
│    │     "processing_time_seconds": 45.23            │       │
│    │   }                                             │       │
│    │ - summary (human-readable summary)              │       │
│    │ - visualizations (chart.js configs)             │       │
│    └────────────────────────────────────────────────┘       │
│                                                               │
└──────────────────────────────────────────────────────────────┘
                        │
┌───────────────────────▼───────────────────────────────────────┐
│ 7. ACTIVE LEARNING & USER FEEDBACK                            │
│                                                               │
│    User Reviews & Corrects Predictions:                       │
│                                                               │
│    ┌────────────────────────────────────────────────┐       │
│    │ TrainingItem Record (per prediction):          │       │
│    │ - text_content (original text)                  │       │
│    │ - predicted_sentiment (AI output)               │       │
│    │ - detected_aspects (AI output - array)          │       │
│    │ - confidence_score (AI confidence)              │       │
│    │                                                 │       │
│    │ - corrected_sentiment (user correction)         │       │
│    │ - corrected_aspects (user correction - array)   │       │
│    │ - correction_notes (user comments)              │       │
│    │ - is_corrected (boolean flag)                   │       │
│    │ - verified_at, verified_by (audit)              │       │
│    └────────────────────────────────────────────────┘       │
│                                                               │
│    User Feedback Submission:                                  │
│    AnalysisFeedbackController::store()                       │
│    └─ Accepts corrections → updates TrainingItems           │
│       └─ Triggers ModelEvaluationService                    │
│                                                               │
└──────────────────────────────────────────────────────────────┘
                        │
┌───────────────────────▼───────────────────────────────────────┐
│ 8. MODEL EVALUATION & METRICS COMPUTATION                      │
│                                                               │
│    ModelEvaluationService::buildEvaluationSummary()          │
│                                                               │
│    For Each Analysis Type:                                    │
│                                                               │
│    ┌─────────────────────────────────────────┐              │
│    │ Sentiment Evaluation:                    │              │
│    │ ├─ Confusion Matrix (3x3):               │              │
│    │ │   [actual][predicted] = count          │              │
│    │ ├─ Per-class Metrics:                    │              │
│    │ │   - Precision = TP/(TP+FP)             │              │
│    │ │   - Recall = TP/(TP+FN)                │              │
│    │ │   - F1-Score = 2*(P*R)/(P+R)           │              │
│    │ │   - Support (count)                    │              │
│    │ ├─ Macro-Average F1                      │              │
│    │ ├─ Weighted F1 (by support)              │              │
│    │ └─ Overall Accuracy                      │              │
│    └─────────────────────────────────────────┘              │
│                                                               │
│    ┌─────────────────────────────────────────┐              │
│    │ Aspect Evaluation:                       │              │
│    │ ├─ Aspect Detection Accuracy             │              │
│    │ ├─ Aspect F1-Score (if using ML)         │              │
│    │ ├─ Sentiment per Aspect                  │              │
│    │ └─ Most Common Corrected Aspects         │              │
│    └─────────────────────────────────────────┘              │
│                                                               │
│    ┌─────────────────────────────────────────┐              │
│    │ Topic Evaluation:                        │              │
│    │ ├─ Topic coherence (if supported)        │              │
│    │ ├─ Top topic distribution                │              │
│    │ └─ Most frequent topics in corrections   │              │
│    └─────────────────────────────────────────┘              │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

### 3.2 Preprocessing Pipeline

**Configuration-Driven Text Normalization:**

```
Raw Text Input
    │
    ├─ case_folding ──────────► "This is GOOD!" → "this is good!"
    │
    ├─ remove_punctuation ────► "this is good!" → "this is good"
    │
    ├─ remove_numbers ────────► "item #5" → "item"
    │
    ├─ tokenization ──────────► "this is good" → ["this", "is", "good"]
    │
    ├─ remove_stopwords ──────► ["this", "is", "good"] → ["good"]
    │                           (using default + custom lists)
    │
    ├─ stemming (optional) ───► "running" → "run"
    │
    └─ lemmatization (optional)─► "better" → "good"
                                   (requires Indonesian morphology data)

Output: Preprocessed, normalized tokens
```

**Custom Stopword Management:**

```php
CustomStopword Model:
├─ word (string) ........................ Stopword to filter
├─ added_by (user_id) .................. Admin who added it
└─ created_at

PreprocessingConfig::custom_stopwords (array)
└─ Dynamically loaded before API calls
```

### 3.3 Modeling & Classification

**Model Ensemble (External Python Backend):**

The Python NLP API implements multiple classification models, likely including:
- Logistic Regression (baseline)
- Decision Trees
- Random Forest
- Support Vector Machines (SVM)
- Naive Bayes
- Neural Networks (LSTM/BERT)

**Selection Criteria:**
- Model selection determined by Python backend (not visible in Laravel code)
- Confidence scores returned per prediction
- Batch processing for large datasets (50 texts per request)

### 3.4 Evaluation Metrics

**For Sentiment Classification (3-class: Positive, Neutral, Negative):**

| Metric | Formula | Purpose |
|--------|---------|---------|
| **Accuracy** | (TP+TN)/(TP+TN+FP+FN) | Overall correctness |
| **Precision** | TP/(TP+FP) | Correctness when predicting class |
| **Recall** | TP/(TP+FN) | Coverage of actual class instances |
| **F1-Score** | 2*(P*R)/(P+R) | Harmonic mean of precision & recall |
| **Weighted F1** | Σ(support_i * F1_i) / total | Average F1 weighted by class support |
| **Confusion Matrix** | 3×3 grid of predicted vs. actual | Detailed error analysis |

**Example Sentiment Confusion Matrix:**

```
                    Predicted
                 Pos  Neg  Neu
Actual  Positive  35    2    3
        Negative   1   18    1
        Neutral    2    1   37
```

**For Aspect Extraction:**
- Aspect Detection Accuracy (% of correctly identified aspects)
- Aspect F1-Score (if using learned model)
- Per-aspect sentiment distribution

**For Topic Modeling:**
- Topic coherence (if supported)
- Topic distribution histograms
- Document-topic assignments

### 3.5 Active Learning Loop

**Continuous Model Improvement Workflow:**

```
1. Initial Prediction
   ├─ AI analyzes texts → stores predictions in AnalysisResult
   └─ Confidence scores stored for each prediction

2. User Review & Feedback
   ├─ User views analysis in AnalysisFeedbackController::create()
   ├─ User selects correct sentiment/aspects for subset of texts
   ├─ User submits via AnalysisFeedbackController::store()
   └─ Corrections stored in TrainingItem table (is_corrected=true)

3. Evaluation Computation
   ├─ ModelEvaluationService::buildEvaluationSummary()
   ├─ Computes confusion matrix from corrected items
   ├─ Calculates accuracy, precision, recall, F1
   └─ Stores metrics in AnalysisResult.metrics JSON

4. Training Data Accumulation
   ├─ Corrected TrainingItems accumulate in database
   ├─ Each TrainingItem.verified_at records feedback timestamp
   └─ Training data ready for external model retraining

5. Model Retraining (External Process)
   ├─ Python backend periodically fetches corrected training data
   ├─ Retrains classification models
   └─ Updates inference pipeline (outside Laravel scope)

6. Performance Monitoring
   ├─ Track metrics over time (per analysis type)
   ├─ Identify problematic classes/aspects
   └─ Alert on model degradation
```

---

## 4. Architecture & Directory Structure

### 4.1 Critical Directory Map

```
c:\Skripsi\text-analysis-web\
│
├── 📁 app/                           # Laravel application code (PHP)
│   ├── 📁 Http/
│   │   ├── Controllers/
│   │   │   ├── AnalysisController.php          ★ Main analysis CRUD, file upload, job dispatch
│   │   │   ├── AnalysisFeedbackController.php  ★ Active learning feedback handling
│   │   │   ├── DashboardController.php         ★ User dashboard & stats
│   │   │   ├── LandingController.php           ★ Public landing page
│   │   │   ├── ProfileController.php           ★ User profile management
│   │   │   ├── YouTubeScraperController.php    ★ YouTube data ingestion (optional)
│   │   │   └── Admin/
│   │   │       └── TrainingController.php      ★ Training data management (admin)
│   │   ├── Middleware/
│   │   ├── Requests/                 # Form validation rules
│   │   └── Controller.php
│   │
│   ├── 📁 Models/                    ★★★ Core data models
│   │   ├── User.php                         # User account with soft delete
│   │   ├── TextAnalysis.php                 ★ Main analysis record (status, progress, metadata)
│   │   ├── AnalysisResult.php               ★ ML output (predictions, metrics, sentiment_distribution)
│   │   ├── AnalysisLog.php                  # Audit log (who did what, when)
│   │   ├── TrainingItem.php                 ★ Active learning: single prediction + corrections
│   │   ├── PreprocessingConfig.php          ★ Preprocessing options (case folding, stopwords, etc.)
│   │   ├── CustomStopword.php               ★ Admin-defined stopwords for text cleaning
│   │   ├── Dataset.php                      # (Purpose unclear from code, may be deprecated)
│   │   └── Relationships & Scopes: All defined via Eloquent
│   │
│   ├── 📁 Services/                  ★★★ Business logic & external integrations
│   │   ├── NLPApiService.php                 ★ HTTP client for Python NLP backend
│   │   │                                    ├─ testConnection()
│   │   │                                    ├─ preprocessText()
│   │   │                                    ├─ analyzeSentiment() [with batch processing]
│   │   │                                    ├─ analyzeAspect()
│   │   │                                    ├─ analyzeTopic()
│   │   │                                    └─ batchSentimentAnalysis()
│   │   ├── FileProcessingService.php        ★ File upload & text extraction
│   │   │                                    ├─ saveFile() [store to storage]
│   │   │                                    ├─ processFile() [preview for AJAX]
│   │   │                                    ├─ previewExcelFile()
│   │   │                                    ├─ previewCsvFile()
│   │   │                                    ├─ previewTxtFile()
│   │   │                                    ├─ processExcelWithConfig()
│   │   │                                    ├─ processCsvWithConfig()
│   │   │                                    └─ processTxtWithConfig()
│   │   ├── ModelEvaluationService.php       ★ Metric computation & confusion matrix
│   │   │                                    ├─ buildEvaluationSummary()
│   │   │                                    ├─ buildSentimentEvaluation()
│   │   │                                    ├─ buildAspectEvaluation()
│   │   │                                    ├─ buildTopicEvaluation()
│   │   │                                    └─ Helper metrics methods
│   │   └── TrainingItemService.php          ★ Training data extraction & management
│   │
│   ├── 📁 Jobs/
│   │   └── ProcessTextAnalysis.php          ★★★ Async queue job for analysis execution
│   │                                        ├─ Orchestrates entire ML pipeline
│   │                                        ├─ Calls NLPApiService endpoints
│   │                                        ├─ Manages progress updates (10%-100%)
│   │                                        ├─ Error handling with retry logic
│   │                                        ├─ timeout=1800s (30 min for large data)
│   │                                        └─ tries=3 with backoff [2min, 5min, 10min]
│   │
│   ├── 📁 Policies/
│   │   └── AnalysisPolicy.php               # Authorization rules (user owns analysis)
│   │
│   ├── 📁 Console/
│   │   └── Commands/                        # Artisan CLI commands
│   │
│   └── 📁 Providers/
│       └── AppServiceProvider.php           # Service container binding
│
├── 📁 database/                     ★★★ Schema & migrations
│   ├── 📁 migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php
│   │   ├── 0001_01_01_000001_create_cache_table.php
│   │   ├── 0001_01_01_000002_create_jobs_table.php  ← Queue jobs table
│   │   ├── 2025_11_09_105417_create_text_analyses_table.php        ★
│   │   ├── 2025_11_09_105437_create_analysis_results_table.php     ★
│   │   ├── 2025_11_09_105438_create_analysis_logs_table.php        ★
│   │   ├── 2025_11_09_105438_create_preprocessing_configs_table.php ★
│   │   ├── 2025_11_09_105439_create_datasets_table.php
│   │   ├── 2025_11_15_144722_add_metadata_to_text_analyses_table.php
│   │   ├── 2026_01_13_144431_add_progress_tracking_to_text_analyses_table.php
│   │   ├── 2026_01_17_140246_update_tables_for_active_learning.php  ★
│   │   └── 2026_01_19_092257_create_active_learning_tables.php      ★
│   │
│   ├── 📁 factories/
│   │   └── UserFactory.php                  # Faker for testing
│   │
│   └── 📁 seeders/                  # Database seeding (initial data)
│
├── 📁 routes/
│   ├── web.php                      ★ Main web routes (analysis, feedback, dashboard)
│   ├── auth.php                     # Authentication routes (Breeze)
│   └── console.php                  # Console command routes
│
├── 📁 resources/                    ★★★ Frontend views & assets
│   ├── 📁 views/
│   │   ├── 📁 analysis/
│   │   │   ├── create.blade.php             # File upload form & config
│   │   │   ├── index.blade.php              # Analysis list
│   │   │   ├── show.blade.php               # Results display (charts, metrics)
│   │   │   └── feedback.blade.php           ★ User correction form (active learning)
│   │   ├── 📁 admin/
│   │   │   └── preprocessing-config.blade.php
│   │   ├── 📁 dashboard/
│   │   │   └── index.blade.php              # User dashboard
│   │   ├── 📁 layouts/
│   │   │   ├── app.blade.php                # Main layout
│   │   │   └── auth.blade.php               # Auth layout
│   │   ├── 📁 components/
│   │   │   └── [Reusable components]
│   │   └── landing.blade.php                # Public landing page
│   │
│   ├── 📁 css/
│   │   └── app.css                  # Tailwind CSS imports
│   │
│   └── 📁 js/
│       └── app.js                   # Alpine.js & main JS
│
├── 📁 config/                       ★ Configuration files
│   ├── app.php                      # App name, timezone, locale
│   ├── database.php                 # DB connection
│   ├── services.php                 ★ External service configs (NLP API URL, timeout)
│   ├── queue.php                    # Queue driver (database, redis, sync)
│   └── [others]
│
├── 📁 storage/
│   ├── 📁 app/
│   │   ├── 📁 uploads/              # User-uploaded CSV/Excel/TXT files
│   │   └── 📁 pdf/                  # Generated PDF reports
│   ├── 📁 framework/
│   │   ├── 📁 cache/
│   │   └── 📁 sessions/
│   └── 📁 logs/
│       └── laravel.log              # Application logs
│
├── 📁 public/
│   ├── 📁 build/                    # Vite compiled CSS/JS
│   ├── 📁 storage/                  # Symlink to storage/app/public
│   └── index.php                    # Entry point
│
├── 📁 bootstrap/
│   ├── app.php                      # Bootstrap application
│   └── 📁 cache/
│
├── 📁 tests/
│   ├── 📁 Feature/
│   ├── 📁 Unit/
│   └── TestCase.php
│
├── 📁 vendor/                       # Composer dependencies
│
├── .env                             # Environment variables (IGNORED in git)
├── .env.example                     # Template for .env
├── artisan                          # Laravel CLI
├── composer.json                    ★ PHP dependencies
├── composer.lock                    # Locked versions
├── package.json                     ★ Node/npm dependencies
├── package-lock.json
├── vite.config.js                   ★ Vite configuration
├── tailwind.config.js               # Tailwind CSS config
├── postcss.config.js                # PostCSS plugins
├── phpunit.xml                      # PHPUnit test config
└── README.md                        # Project documentation
```

### 4.2 Key Data Models & Relationships

```
┌─────────────────────────────────┐
│          User                   │
│ (Laravel Breeze Auth)          │
├─────────────────────────────────┤
│ id, email, password             │
│ email_verified_at               │
└─────────────────────────────────┘
        │ 1:N
        │
        ├──────────────────────────────────────┐
        │                                      │
        ▼                                      ▼
┌──────────────────────┐      ┌──────────────────────┐
│  TextAnalysis        │      │  CustomStopword      │
├──────────────────────┤      ├──────────────────────┤
│ id                   │      │ id, word             │
│ user_id (FK)         │      │ added_by (FK → User) │
│ title, description   │      │ created_at           │
│ input_type           │      └──────────────────────┘
│ analysis_type        │
│ status               │
│ raw_data (JSON)      │
│ file_path, file_name │
│ metadata (JSON)      │
│ progress (0-100%)    │
│ current_step         │
│ error_message        │
│ started_at           │
│ completed_at         │
│ last_polled_at       │
└──────────────────────┘
        │ 1:N (logs)
        │
        ├────────────────────────────┬──────────────┐
        │                            │              │
        ▼                            ▼              ▼
┌──────────────────────┐  ┌──────────────────────┐
│  AnalysisLog         │  │  AnalysisResult      │
├──────────────────────┤  ├──────────────────────┤
│ id                   │  │ id                   │
│ user_id (FK)         │  │ text_analysis_id (FK)│ 1:1
│ text_analysis_id(FK) │  │ preprocessed_data    │
│ action (string)      │  │ predictions (JSON)   │
│ description          │  │ sentiment_dist (JSON)│
│ metadata (JSON)      │  │ aspect_results (JSON)│
│ ip_address, user_agent
│ created_at           │  │ topic_results (JSON) │
└──────────────────────┘  │ metrics (JSON)       │
                          │ summary (text)       │
                          │ visualizations (JSON)│
                          │ corrected_sentiment  │
                          │ corrected_aspects    │
                          │ verified_at          │
                          │ verified_by (FK)     │
                          └──────────────────────┘
                                  │ 1:N (training items)
                                  │
                                  ▼
                          ┌──────────────────────┐
                          │  TrainingItem        │ ★ Active Learning
                          ├──────────────────────┤
                          │ id                   │
                          │ text_analysis_id(FK) │
                          │ text_content         │
                          │ predicted_sentiment  │
                          │ detected_aspects(JSON
                          │ confidence_score     │
                          │ corrected_sentiment  │
                          │ corrected_aspects(JSON
                          │ correction_notes     │
                          │ is_corrected (bool)  │
                          │ verified_at          │
                          │ verified_by (FK)     │
                          └──────────────────────┘

┌──────────────────────┐
│ PreprocessingConfig  │ ★ Reusable configs
├──────────────────────┤
│ id                   │
│ name                 │
│ description          │
│ case_folding (bool)  │
│ remove_punctuation   │
│ remove_numbers       │
│ remove_stopwords     │
│ stemming             │
│ lemmatization        │
│ custom_stopwords(JSON
│ is_default (bool)    │
└──────────────────────┘
```

### 4.3 Request/Response Flow

**Example: User Creates Analysis → Processes → Provides Feedback**

```
┌─────────────────────────────────────────────────────────┐
│ STEP 1: Create Analysis (Authenticated User)            │
└─────────────────────────────────────────────────────────┘

GET /analysis/create
  │
  ├─ AnalysisController::create()
  │  ├─ PreprocessingConfig::all() → load available configs
  │  └─ render view('analysis.create')
  │
  └─► Response: HTML form with:
      - Title, description fields
      - Input type toggle (file/manual)
      - File upload widget (AJAX)
      - Preprocessing config selector


┌─────────────────────────────────────────────────────────┐
│ STEP 2: File Preview (AJAX)                             │
└─────────────────────────────────────────────────────────┘

POST /analysis/upload-file (multipart/form-data)
  │
  ├─ AnalysisController::uploadFile()
  │  ├─ FileProcessingService::processFile()
  │  │  ├─ Detect file type (xlsx/csv/txt)
  │  │  └─ previewExcelFile() / previewCsvFile() / previewTxtFile()
  │  │     ├─ Load first N rows
  │  │     ├─ Extract headers
  │  │     └─ Return structure info
  │  │
  │  └─ response()->json(['success' => true, 'data' => {...}])
  │
  └─► Response: {
        "success": true,
        "data": {
          "headers": ["text", "date", "category"],
          "sheets": ["Sheet1", "Sheet2"],
          "sample_data": [[...], [...], ...],
          "row_count": 250
        }
      }

  └─ Frontend (Alpine.js) displays preview & asks for column config


┌─────────────────────────────────────────────────────────┐
│ STEP 3: Submit Analysis                                 │
└─────────────────────────────────────────────────────────┘

POST /analysis/store
  │
  ├─ Validate input (title, description, file/text, config)
  │
  ├─ AnalysisController::store()
  │  ├─ If file upload:
  │  │  └─ FileProcessingService::processFileWithConfig()
  │  │     ├─ Parse Excel/CSV/TXT with user-selected options
  │  │     └─ Extract text column → array of strings
  │  │
  │  ├─ If manual text:
  │  │  └─ Split by newline or as-is
  │  │
  │  ├─ Create TextAnalysis record:
  │  │  {
  │  │    user_id: auth()->id(),
  │  │    title: 'My Analysis',
  │  │    analysis_type: 'sentiment',
  │  │    input_type: 'file',
  │  │    raw_data: [...100 texts...],
  │  │    file_path: 'uploads/1234_data.xlsx',
  │  │    status: 'pending',
  │  │    progress: 0,
  │  │    metadata: {
  │  │      preprocessing_config_id: 5,
  │  │      file_has_header: true,
  │  │      text_column_name: 'text',
  │  │      ...
  │  │    }
  │  │  }
  │  │
  │  └─ Dispatch ProcessTextAnalysis job to queue:
  │     ProcessTextAnalysis::dispatch($analysis)
  │
  └─► Redirect to /analysis/{id}/show (with status pending)


┌─────────────────────────────────────────────────────────┐
│ STEP 4: Async Processing (Background Job)               │
└─────────────────────────────────────────────────────────┘

Queue Worker Picks Up ProcessTextAnalysis Job
  │
  ├─ ProcessTextAnalysis::handle(NLPApiService $nlpService)
  │
  ├─ $analysis->updateProgress(10, 'Starting analysis...')
  │
  ├─ Get PreprocessingConfig from metadata
  │
  ├─ Call NLPApiService method based on analysis_type:
  │
  │  Case 'sentiment':
  │  ├─ $nlpService->analyzeSentiment($texts, $config, $progressCallback)
  │  │  ├─ If count > 50: batchSentimentAnalysis() (multiple API calls)
  │  │  └─ Each batch: POST /api/analyze/sentiment to Python backend
  │  │     Request: { "texts": [...50 texts], "preprocessing_config": {...} }
  │  │     Response: {
  │  │       "results": [
  │  │         { "label": "positive", "confidence": 0.95, "probabilities": {...} },
  │  │         { "label": "negative", "confidence": 0.87, ... },
  │  │         ...
  │  │       ]
  │  │     }
  │  └─ Aggregate results from all batches
  │
  │  Case 'aspect':
  │  └─ $nlpService->analyzeAspect($texts, $config, $aspects, $mode)
  │     └─ Returns: { "results": [{ "aspects": [...], "sentiment": "positive" }, ...] }
  │
  │  Case 'topic':
  │  └─ $nlpService->analyzeTopic($texts, $config, $num_topics)
  │     └─ Returns: { "topics": [...], "document_topics": [...] }
  │
  │  Case 'combined':
  │  └─ Run all three sequentially
  │
  ├─ $analysis->updateProgress(80, 'Computing metrics...')
  │
  ├─ Create AnalysisResult record:
  │  {
  │    text_analysis_id: $analysis->id,
  │    preprocessed_data: [...],
  │    predictions: [...all raw results...],
  │    sentiment_distribution: { positive: 35, negative: 20, neutral: 45 },
  │    aspect_results: { ... },
  │    topic_results: { ... },
  │    metrics: {
  │      total_texts: 100,
  │      processing_time: 45.23,
  │      ...
  │    }
  │  }
  │
  ├─ Extract individual TrainingItems from predictions:
  │  for each prediction:
  │    TrainingItem::create({
  │      text_analysis_id: $analysis->id,
  │      text_content: original_text,
  │      predicted_sentiment: "positive",
  │      detected_aspects: ["price", "quality"],
  │      confidence_score: 0.95
  │    })
  │
  ├─ $analysis->update({
  │    status: 'completed',
  │    completed_at: now(),
  │    progress: 100
  │  })
  │
  └─ AnalysisLog::createLog('completed', ...)


┌─────────────────────────────────────────────────────────┐
│ STEP 5: Frontend Polling for Status Updates              │
└─────────────────────────────────────────────────────────┘

JavaScript (Alpine.js) on show.blade.php:

const pollStatus = async () => {
  const response = await fetch(`/analysis/{id}/poll-status`);
  const data = await response.json();
  // { status: 'processing', progress: 45, current_step: 'Analyzing...' }
  updateProgressBar(data.progress);
  if (data.status !== 'completed') {
    setTimeout(pollStatus, 2000); // poll every 2 seconds
  }
};

GET /analysis/{id}/poll-status
  │
  ├─ AnalysisController::pollStatus()
  │  ├─ Authorize (user owns analysis)
  │  └─ return $analysis->only(['status', 'progress', 'current_step'])
  │
  └─► Response: {
        "status": "processing",
        "progress": 65,
        "current_step": "Computing metrics..."
      }


┌─────────────────────────────────────────────────────────┐
│ STEP 6: Display Results                                 │
└─────────────────────────────────────────────────────────┘

GET /analysis/{id} (after processing complete)
  │
  ├─ AnalysisController::show()
  │  ├─ $analysis = TextAnalysis::with('result')->findOrFail($id)
  │  ├─ Authorize (user owns analysis)
  │  └─ render view('analysis.show', compact('analysis'))
  │
  └─► Response: HTML with:
      - Title & metadata
      - Sentiment distribution pie chart (Chart.js)
      - Aspect breakdown table
      - Topic cloud (if topic analysis)
      - "Provide Feedback" button
      - Export PDF / Export CSV buttons


┌─────────────────────────────────────────────────────────┐
│ STEP 7: Provide Feedback (Active Learning)              │
└─────────────────────────────────────────────────────────┘

GET /analysis/{id}/feedback
  │
  ├─ AnalysisFeedbackController::create()
  │  ├─ Load $analysis->trainingItems() (paginated)
  │  ├─ If first time: TrainingItemService::extractJsonToTable()
  │  │  └─ Extracts predictions JSON → TrainingItem records
  │  └─ render view('analysis.feedback')
  │
  └─► Response: HTML form with paginated list of:
      ┌────────────────────────────────────────┐
      │ Text: "This product is amazing!"      │
      │ AI Predicted: Positive (95%)           │
      │ Detected Aspects: [price, quality]    │
      │                                        │
      │ Your Correction:                       │
      │ ○ Positive ● Negative ○ Neutral       │
      │ ☑ price ☑ quality ☐ service          │
      │ Notes: (textarea)                      │
      └────────────────────────────────────────┘
      ... (100+ more items paginated)
      [Submit Corrections] [Export Report]

POST /analysis/{id}/feedback
  │
  ├─ AnalysisFeedbackController::store()
  │  ├─ Validate corrections array
  │  ├─ For each correction:
  │  │  └─ TrainingItem::updateOrCreate({
  │  │      text_analysis_id, text_id
  │  │    }, {
  │  │      corrected_sentiment: user_correction,
  │  │      corrected_aspects: [...],
  │  │      correction_notes: user_notes,
  │  │      is_corrected: true,
  │  │      verified_at: now(),
  │  │      verified_by: auth()->id()
  │  │    })
  │  │
  │  ├─ Compute evaluation metrics:
  │  │  └─ ModelEvaluationService::buildEvaluationSummary()
  │  │     ├─ Get all corrected TrainingItems
  │  │     ├─ Compute confusion matrix (sentiment)
  │  │     ├─ Calculate: Accuracy, Precision, Recall, F1 per class
  │  │     ├─ Aggregate per-class metrics
  │  │     └─ return evaluation summary array
  │  │
  │  └─ AnalysisResult::update(['metrics' => $evaluation])
  │
  └─► Redirect with success message showing:
      "Feedback submitted! Accuracy: 92.5%, F1-Score: 0.91"


┌─────────────────────────────────────────────────────────┐
│ STEP 8: View Evaluation Metrics                          │
└─────────────────────────────────────────────────────────┘

GET /analysis/{id}/evaluation (or on show page)

Display computed metrics (from AnalysisResult.metrics):
  ├─ Sentiment Evaluation:
  │  ├─ Accuracy: 92.5%
  │  ├─ Per-class:
  │  │  ├─ Positive: P=94%, R=90%, F1=0.92, Support=35
  │  │  ├─ Negative: P=88%, R=92%, F1=0.90, Support=20
  │  │  └─ Neutral: P=93%, R=94%, F1=0.93, Support=45
  │  └─ Weighted F1: 0.917
  │
  ├─ Aspect Evaluation:
  │  ├─ Most frequent: [price (42%), quality (38%), service (20%)]
  │  └─ Correction rate: 15% (of 100 items)
  │
  └─ Topic Evaluation:
     ├─ Top 5 topics with word clouds
     └─ Document distribution
```

---

## 5. Strict Rules for AI System Modifications

**ANY AI SYSTEM modifying this codebase MUST strictly adhere to the following rules:**

### 5.1 Code Quality & Standards

1. **PHP/Laravel Coding Standard (PSR-12 with Laravel Conventions)**
   - Class names: PascalCase (e.g., `TextAnalysis`, `AnalysisController`)
   - Method names: camelCase (e.g., `processTextAnalysis()`, `buildEvaluationSummary()`)
   - Property names: camelCase with visibility modifiers (private, protected, public)
   - Indentation: 4 spaces (no tabs)
   - Line length: max 120 characters (preferred 80 for readability)
   - **RULE:** Any code changes must pass `php artisan pint` (Laravel code style fixer)
   - **RULE:** No unused imports, properties, or methods
   - **RULE:** All public methods/classes must have PHPDoc comments with `@param`, `@return` tags

   ```php
   ✓ CORRECT:
   class TextAnalysisService
   {
       public function analyzeText(string $text): array
       {
           // implementation
       }
   }
   
   ✗ WRONG:
   class TextAnalysisService {
       public function analyzeText($text) { /* wrong */ }
   }
   ```

2. **Database Migrations Must Be Immutable**
   - NEVER modify existing migrations (they may be deployed in production)
   - ALWAYS create NEW migrations for schema changes (e.g., `2026_05_19_add_field_to_table.php`)
   - **RULE:** Every migration must have both `up()` and `down()` methods (rollback support)
   - **RULE:** Foreign keys must use `->constrained()` for implicit naming or explicit naming
   - **RULE:** All JSON columns must be explicitly typed: `$table->json('field_name')`

   ```php
   ✓ CORRECT:
   public function up(): void
   {
       Schema::create('new_table', function (Blueprint $table) {
           $table->id();
           $table->foreignId('user_id')->constrained()->cascadeOnDelete();
           $table->json('metadata')->default('{}');
       });
   }
   
   ✗ WRONG:
   // Modifying existing migration or missing down()
   ```

3. **Model Relationships Must Use Type Hints**
   - Return type hints required for all relationship methods
   - Explicit relation definitions (BelongsTo, HasMany, etc.)
   - **RULE:** Use explicit `->withTrashed()` for soft-deleted models when needed

   ```php
   ✓ CORRECT:
   public function result(): HasOne
   {
       return $this->hasOne(AnalysisResult::class);
   }
   
   ✗ WRONG:
   public function result() { /* missing return type */ }
   ```

### 5.2 Machine Learning Pipeline Integrity

4. **NLP API Contract Must Never Be Broken**
   - **RULE:** All HTTP requests to Python NLP backend MUST use the exact JSON structure defined in `NLPApiService.php`
   - **RULE:** Timeouts for NLP API calls MUST be >= 7200 seconds (2 hours for topic modeling on 10k+ texts)
   - **RULE:** Batch size MUST NOT exceed `config('services.nlp_api.batch_size')` (default 50)
   - **RULE:** Error responses from NLP API MUST be caught and logged (not silently ignored)
   - **RULE:** If NLP API endpoint changes, update in ONE place: `NLPApiService.php` constructor

   ```php
   ✓ CORRECT:
   try {
       $response = Http::timeout($this->timeout)->post($endpoint, $payload);
       if (!$response->successful()) {
           Log::error('NLP API Error', $response->json());
           throw new Exception('...');
       }
   } catch (Exception $e) {
       AnalysisLog::createLog('error', null, $analysis->id, $e->getMessage());
       throw $e;
   }
   
   ✗ WRONG:
   // Changing timeout to 60s
   // Or not logging errors
   // Or hardcoding API URL instead of using config()
   ```

5. **Progress Tracking Must Be Consistent**
   - **RULE:** Progress percentage in `ProcessTextAnalysis` job MUST increase monotonically (10%, 20%, 30%... 100%)
   - **RULE:** Progress steps MUST match the step names users see in frontend
   - **RULE:** Never skip progress updates; use 10% increments minimum
   - **RULE:** All async operations MUST update `$analysis->progress` and `current_step` together:

   ```php
   ✓ CORRECT:
   private function updateProgress(int $progress, string $step): void
   {
       $this->analysis->updateProgress($progress, $step);
   }
   // Called: updateProgress(30, 'Preprocessing...');
   //         updateProgress(50, 'Analyzing...');
   //         updateProgress(100, 'Complete');
   
   ✗ WRONG:
   // Progress: 10%, 20%, 15%, 90%, 100% (non-monotonic)
   ```

6. **Active Learning Data Integrity**
   - **RULE:** User corrections in `TrainingItem` MUST be immutable once verified (add `->update(['verified_by' => null])` never fully delete)
   - **RULE:** All corrections MUST have audit trail: `verified_at`, `verified_by`
   - **RULE:** Confusion matrix computation MUST handle missing corrections gracefully
   - **RULE:** Metric calculations MUST use ONLY verified/corrected items (where `is_corrected = true`)

   ```php
   ✗ WRONG:
   // Deleting training items instead of soft-deleting
   // Including unverified corrections in metrics
   ```

### 5.3 Authorization & Security

7. **Resource Ownership Authorization (Crucial)**
   - **RULE:** ALL analysis access MUST check: `$this->authorize('view', $analysis);` in controllers
   - **RULE:** Users can ONLY access their own TextAnalysis records (verify `user_id` match)
   - **RULE:** Admin features (preprocessing configs) MUST require admin role check
   - **RULE:** Never trust `request()->get('id')` without authorization check

   ```php
   ✓ CORRECT:
   public function show($id)
   {
       $analysis = TextAnalysis::findOrFail($id);
       $this->authorize('view', $analysis);
       // ... continue
   }
   
   ✗ WRONG:
   // Missing authorize() call
   ```

8. **File Upload Security**
   - **RULE:** File uploads MUST validate mime types: `'file' => 'required|file|mimes:csv,txt,xlsx,xls'`
   - **RULE:** File size limit MUST be enforced: `max:10240` (10MB)
   - **RULE:** Uploaded files MUST be stored in `storage/app/` (NOT web-accessible)
   - **RULE:** User uploads MUST be in isolated directories per user: `uploads/{user_id}/`

### 5.4 Testing & Quality Assurance

9. **Test Coverage Requirements**
   - **RULE:** Any new controller action MUST have feature test in `tests/Feature/`
   - **RULE:** Any new service method MUST have unit test in `tests/Unit/`
   - **RULE:** Tests MUST use database transactions (no data pollution between tests)
   - **RULE:** All tests MUST be runnable via `php artisan test` with 100% pass rate
   - **RULE:** Test class names MUST end with `Test` (e.g., `AnalysisControllerTest`)

10. **Logging & Error Handling**
    - **RULE:** All exceptions MUST be logged via `Log::error()` with context
    - **RULE:** User-friendly error messages MUST be returned to frontend (detailed logs only server-side)
    - **RULE:** External API failures MUST be logged and retried with exponential backoff
    - **RULE:** Database errors MUST NOT expose raw SQL to users

    ```php
    ✓ CORRECT:
    try {
        // operation
    } catch (Exception $e) {
        Log::error("Full context: {$e->getMessage()}", ['trace' => $e]);
        return response()->json([
            'success' => false,
            'message' => 'An error occurred. Please try again.'
        ], 500);
    }
    ```

### 5.5 CRISP-ML(Q) Pipeline Compliance

11. **Data Preparation Phase Integrity**
    - **RULE:** All preprocessing operations MUST be: (a) configurable, (b) logged, (c) reproducible
    - **RULE:** Custom stopwords MUST be stored in DB (CustomStopword model), never hardcoded
    - **RULE:** Preprocessing config MUST be versioned (link analysis to specific config ID)
    - **RULE:** Never modify raw_data after analysis is created; store in separate preprocessed_data field

12. **Model Evaluation Must Be Rigorous**
    - **RULE:** Confusion matrices MUST be computed correctly (TP, FP, TN, FN placement)
    - **RULE:** Metrics MUST handle class imbalance (use weighted averages, per-class reports)
    - **RULE:** Accuracy MUST NOT be the only metric (also use Precision, Recall, F1)
    - **RULE:** All metric computations MUST be verifiable with sample data

    **Reference Formula:**
    ```
    Precision = TP / (TP + FP)
    Recall = TP / (TP + FN)
    F1 = 2 * (Precision * Recall) / (Precision + Recall)
    Accuracy = (TP + TN) / (TP + TN + FP + FN)
    ```

### 5.6 Documentation & Maintenance

13. **Code Documentation Requirements**
    - **RULE:** All public classes/methods MUST have PHPDoc comments
    - **RULE:** Complex algorithms (e.g., metric computation) MUST have inline comments explaining logic
    - **RULE:** Magic numbers MUST be named constants or config values
    - **RULE:** This `SYSTEM_ARCHITECTURE.md` MUST be updated when:
      - New models are created
      - New external integrations added
      - Data flow changes
      - ML pipeline modifications

14. **Backward Compatibility in Active Learning**
    - **RULE:** NEVER change TrainingItem schema without migration
    - **RULE:** NEVER remove fields from AnalysisResult predictions JSON
    - **RULE:** If API response format changes, handle both old and new formats gracefully
    - **RULE:** Deprecated features MUST have 2 release cycles before removal

---

## 6. Deployment & Configuration Checklist

### 6.1 Environment Variables (`.env`)

```bash
# Core
APP_NAME="Text Analysis Web"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=text_analysis
DB_USERNAME=root
DB_PASSWORD=***

# NLP API (CRITICAL)
NLP_API_URL=http://localhost:8001
NLP_API_TIMEOUT=7200
NLP_API_BATCH_SIZE=50
NLP_API_MAX_TEXTS=100

# Queue (for async processing)
QUEUE_CONNECTION=database  # or redis for production
CACHE_DRIVER=redis

# File Storage
FILESYSTEM_DISK=public
APP_STORAGE_PATH=/storage

# Mail (for notifications)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
```

### 6.2 Server Requirements

- PHP 8.2+
- MySQL 5.7+ or MariaDB 10.3+
- Redis (recommended for queue/cache)
- Node.js 18+ (for frontend build only)
- Minimum 2GB RAM (4GB+ for large datasets)
- 10GB disk space (for file uploads + logs)

### 6.3 Initial Setup

```bash
# 1. Install dependencies
composer install
npm install

# 2. Setup environment
cp .env.example .env
php artisan key:generate

# 3. Database setup
php artisan migrate
php artisan db:seed

# 4. Build frontend
npm run build

# 5. Verify NLP API connection
php artisan tinker
# > app('App\Services\NLPApiService')->testConnection()
```

---

## 7. Common Troubleshooting for AI Modifications

| Issue | Root Cause | Solution |
|-------|-----------|----------|
| **"NLP API connection failed"** | External Python service down | Check NLP_API_URL in .env, test: `curl -X GET http://localhost:8001/health` |
| **"Progress stuck at 30%"** | Job timeout too short | Increase NLP_API_TIMEOUT in .env (default 7200s = 2 hours) |
| **"Metrics showing 0% accuracy"** | No corrected training items | Ensure user submitted feedback via `/analysis/{id}/feedback` |
| **"File upload fails silently"** | Storage permissions | Check: `chmod -R 775 storage/ bootstrap/cache/` |
| **"Soft delete not working"** | Model missing SoftDeletes trait | Add: `use SoftDeletes;` in model, run migration |

---

## 8. Quick Reference: Key File Modifications

**When modifying the ML pipeline, update in this order:**

1. **Change data flow:** Modify `ProcessTextAnalysis.php` job → test with `artisan queue:work`
2. **Change API contract:** Update `NLPApiService.php` HTTP calls → test connection: `testConnection()`
3. **Change metrics:** Modify `ModelEvaluationService.php` formulas → add unit tests
4. **Change storage:** Create migration → run `php artisan migrate`
5. **Change UI:** Update Blade templates in `resources/views/` → run `npm run dev`
6. **Update docs:** Refresh this `SYSTEM_ARCHITECTURE.md` file
7. **Run tests:** `php artisan test` (must be 100% passing)

---

**Document Version:** 1.0  
**Last Updated:** May 19, 2026  
**Maintainer Contact:** [Project Owner/Team]
