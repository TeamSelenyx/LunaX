$Host.UI.RawUI.WindowTitle = "LunaX - Vendor Update"
echo "서버 구동에 필요한 vendor 파일들을 업데이트하는 중..."
echo " "
./bin/php/php.exe ./bin/composer.phar update
pause