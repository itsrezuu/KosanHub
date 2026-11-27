pipeline {
    agent any

    options {
        timestamps()
        disableConcurrentBuilds()
    }

    triggers {
        githubPush()
    }

    stages {
        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Test') {
            steps {
                bat 'docker build --target test -t kosanhub-test:%BUILD_NUMBER% .'
            }
        }

        stage('Build Image') {
            steps {
                withCredentials([
                    usernamePassword(
                        credentialsId: 'dockerhub-credentials',
                        usernameVariable: 'DOCKERHUB_USER',
                        passwordVariable: 'DOCKERHUB_TOKEN'
                    )
                ]) {
                    bat '''
                        @echo off
                        docker build --target production ^
                          -t %DOCKERHUB_USER%/kosanhub:%BUILD_NUMBER% ^
                          -t %DOCKERHUB_USER%/kosanhub:latest .
                    '''
                }
            }
        }

        stage('Push Image') {
            steps {
                withCredentials([
                    usernamePassword(
                        credentialsId: 'dockerhub-credentials',
                        usernameVariable: 'DOCKERHUB_USER',
                        passwordVariable: 'DOCKERHUB_TOKEN'
                    )
                ]) {
                    bat '''
                        @echo off
                        echo %DOCKERHUB_TOKEN% | docker login -u %DOCKERHUB_USER% --password-stdin
                        if errorlevel 1 exit /b 1

                        docker push %DOCKERHUB_USER%/kosanhub:%BUILD_NUMBER%
                        if errorlevel 1 exit /b 1

                        docker push %DOCKERHUB_USER%/kosanhub:latest
                    '''
                }
            }
        }

        stage('Deploy') {
            steps {
                withCredentials([
                    usernamePassword(
                        credentialsId: 'dockerhub-credentials',
                        usernameVariable: 'DOCKERHUB_USER',
                        passwordVariable: 'DOCKERHUB_TOKEN'
                    ),
                    file(
                        credentialsId: 'kosanhub-env',
                        variable: 'ENV_FILE'
                    )
                ]) {
                    withEnv([
                        "DOCKERHUB_USERNAME=${env.DOCKERHUB_USER}",
                        "IMAGE_TAG=${env.BUILD_NUMBER}"
                    ]) {
                        bat '''
                            @echo off
                            if exist .env del /f /q .env
                            copy /Y "%ENV_FILE%" .env
                            if errorlevel 1 exit /b 1

                            docker compose pull app worker
                            if errorlevel 1 exit /b 1

                            docker compose up -d --remove-orphans
                        '''
                    }
                }
            }
        }

        stage('Smoke Test') {
            steps {
                withCredentials([
                    usernamePassword(
                        credentialsId: 'dockerhub-credentials',
                        usernameVariable: 'DOCKERHUB_USER',
                        passwordVariable: 'DOCKERHUB_TOKEN'
                    )
                ]) {
                    withEnv([
                        "DOCKERHUB_USERNAME=${env.DOCKERHUB_USER}",
                        "IMAGE_TAG=${env.BUILD_NUMBER}"
                    ]) {
                        bat '''
                            @echo off
                            powershell -NoProfile -ExecutionPolicy Bypass -Command ^
                              "$ErrorActionPreference = 'Stop'; ^
                              $healthOk = $false; ^
                              for ($i = 0; $i -lt 30; $i++) { try { $h = Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost:8000/health' -TimeoutSec 5; if ($h.StatusCode -eq 200) { $healthOk = $true; break } } catch {}; Start-Sleep -Seconds 2 }; ^
                              if (-not $healthOk) { throw 'Smoke Test gagal: /health tidak mengembalikan HTTP 200.' }; ^
                              $startedAt = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'); ^
                              $period = Get-Date -Format 'yyyy-MM'; ^
                              $payload = @{ period = $period } | ConvertTo-Json -Compress; ^
                              $response = Invoke-WebRequest -UseBasicParsing -Method Post -Uri 'http://localhost:8000/api/invoices/generate-monthly' -ContentType 'application/json' -Body $payload -TimeoutSec 15; ^
                              if ($response.StatusCode -ne 202) { throw 'Smoke Test gagal: job invoice tidak masuk antrean.' }; ^
                              $processed = $false; ^
                              for ($i = 0; $i -lt 30; $i++) { $logs = (& docker compose logs --since=$startedAt worker 2>&1 | Out-String); if ($logs -match 'GenerateMonthlyInvoicesJob finished') { $processed = $true; break }; Start-Sleep -Seconds 2 }; ^
                              if (-not $processed) { docker compose logs --tail=100 worker; throw 'Smoke Test gagal: worker belum memproses job invoice.' }; ^
                              Write-Host 'Smoke Test berhasil: /health 200 dan worker memproses job invoice.'"
                        '''
                    }
                }
            }
        }
    }

    post {
        success {
            echo 'Pipeline sukses: image telah di-push dan KosanHub berhasil di-deploy.'
        }
        failure {
            echo 'Pipeline gagal: periksa stage yang merah dan console log.'
        }
        always {
            bat '''
                @echo off
                if exist .env del /f /q .env
                docker logout >nul 2>&1
                exit /b 0
            '''
        }
    }
}
