$Host.UI.RawUI.WindowTitle = "LunaX - Vendor Install"
echo "서버 구동에 필요한 vendor 파일들을 설치하는 중..."
echo " "
./bin/php/php.exe ./bin/composer.phar install
pause